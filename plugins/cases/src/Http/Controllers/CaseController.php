<?php

namespace Plugins\Cases\Http\Controllers;

use App\Core\Accounts\User;
use App\Core\Extensions\Modules\ModuleContext;
use App\Http\Controllers\Controller;
use App\Modules\ModuleInstanceDefinition;
use App\Rules\EligibleRecordOwner;
use App\Rules\EligibleStaffAssignee;
use App\Services\AssignmentAuthorization;
use App\Services\AuditLogger;
use App\Services\SecureUploadService;
use App\Services\SupportAttachmentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Plugins\Cases\CaseCategoryResolver;
use Plugins\Cases\CasesArea;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Cases\Notifications\ApesCicCaseUpdatedNotification;
use Plugins\Cases\Notifications\ShelterCaseUpdatedNotification;
use Plugins\PetProfiles\Contracts\PetProfilesContract;
use Plugins\PetProfiles\Support\StaffPetCreateReturn;

class CaseController extends Controller
{
    public function __construct(
        private readonly ModuleContext $moduleContext,
        private readonly CaseCategoryResolver $categories,
        private readonly SupportAttachmentService $attachments,
        private readonly PetProfilesContract $petProfiles,
    ) {}

    public function index(Request $request): View
    {
        return $this->area()->isShelter()
            ? $this->shelterIndex()
            : $this->cicIndex($request);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        return $this->area()->isShelter()
            ? $this->shelterStore($request, $auditLogger)
            : $this->cicStore($request, $auditLogger);
    }

    public function show(
        Request $request,
        ShelterCase $case,
        AssignmentAuthorization $assignments,
    ): View {
        return $this->area()->isShelter()
            ? $this->shelterShow($case, $assignments)
            : $this->cicShow($request, $case, $assignments);
    }

    public function update(
        Request $request,
        ShelterCase $case,
        AuditLogger $auditLogger,
        AssignmentAuthorization $assignments,
    ): RedirectResponse {
        return $this->area()->isShelter()
            ? $this->shelterUpdate($request, $case, $auditLogger, $assignments)
            : $this->cicUpdate($request, $case, $auditLogger, $assignments);
    }

    public function destroy(
        Request $request,
        ShelterCase $case,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        abort_unless($this->area()->supportsDelete, 404);

        return $this->cicDestroy($request, $case, $auditLogger);
    }

    private function cicIndex(Request $request): View
    {
        $instance = $this->instance($request);
        $prefix = $this->area()->permissionPrefix();
        $user = $request->user();
        abort_unless(
            $user->can($prefix.'view-own') || $user->can($prefix.'view-all'),
            403,
        );

        return view($this->area()->view('index'), [
            'cases' => ShelterCase::query()
                ->forSubCore($instance->subCore->key)
                ->visibleTo($user, $instance->subCore->key)
                ->with(['user', 'assignedTo'])
                ->latest()
                ->paginate(20)
                ->fragment('list'),
            'categoryGroups' => $this->categories->categories($instance->subCore->key),
            'websites' => $this->categories->websites($instance->subCore->key),
            'priorities' => $this->area()->priorities,
            'canCreateCase' => $user->can($prefix.'create'),
            'revealAssigneeIdentity' => $user->can($prefix.'view-all'),
            'categoryResolver' => $this->categories,
        ]);
    }

    private function cicStore(
        Request $request,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $instance = $this->instance($request);
        $prefix = $this->area()->permissionPrefix();
        Gate::authorize($prefix.'create');
        $validated = $request->validate([
            'category' => ['required', Rule::in($this->categories->categoryKeys($instance->subCore->key))],
            'sub_category' => ['required', 'string', 'max:64'],
            'affected_website_key' => ['nullable', 'string', 'max:64'],
            'priority' => ['required', Rule::in($this->area()->priorities)],
            'title' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string'],
            'screenshots' => ['nullable', 'array', 'max:5'],
            'screenshots.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'screencast' => [
                'nullable',
                'file',
                'mimetypes:video/mp4,video/webm',
                'max:'.SecureUploadService::SCREENCAST_MAX_KB,
            ],
        ]);

        $categoryFields = $this->categories->validateSelection(
            $instance->subCore->key,
            $validated['category'],
            $validated['sub_category'],
            $validated['affected_website_key'] ?? null,
        );

        $allowsAttachments = $this->categories->allowsAttachments(
            $instance->subCore->key,
            $categoryFields['category'],
            $categoryFields['sub_category'],
        );
        if (! $allowsAttachments && (
            $request->hasFile('screenshots') || $request->hasFile('screencast')
        )) {
            throw ValidationException::withMessages([
                'screenshots' => __('cases::ui.validation.attachments_are_not_available_for_this_subcategory'),
            ]);
        }

        $case = ShelterCase::create([
            'category' => $categoryFields['category'],
            'sub_category' => $categoryFields['sub_category'],
            'affected_website_key' => $categoryFields['affected_website_key'],
            'priority' => $validated['priority'],
            'title' => $validated['title'],
            'details' => $validated['details'] ?? null,
            'sub_core_key' => $instance->subCore->key,
            'user_id' => $request->user()->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        if ($allowsAttachments) {
            $this->attachments->storeFor(
                $case,
                $instance->subCore->key,
                $request->user(),
                $request->file('screenshots'),
                $request->file('screencast'),
            );
        }

        $this->notifyStakeholders($case, $request->user(), 'created', $instance);
        $auditLogger->record("{$this->area()->auditEventPrefix}.created", $request->user(), $case, [
            'sub_core_key' => $case->sub_core_key,
            'module_key' => $instance->module->key,
            'category' => $case->category,
            'sub_category' => $case->sub_category,
            'affected_website_key' => $case->affected_website_key,
            'priority' => $case->priority,
            'status' => $case->status,
        ]);

        return redirect()->route($this->area()->showRouteName(), $case)
            ->with('status', __('cases::ui.flash.case_created'));
    }

    private function cicShow(
        Request $request,
        ShelterCase $case,
        AssignmentAuthorization $assignments,
    ): View {
        $instance = $this->instance($request);
        $prefix = $this->area()->permissionPrefix();
        $this->requireCaseForInstance($case, $instance);
        Gate::authorize('view', $case);
        $user = $request->user();
        $canViewAll = $user->can($prefix.'view-all');
        $canChangeAssignment = $assignments->allows($user)
            && $user->can($prefix.'assign');
        $allowsAttachments = is_string($case->sub_category)
            && $this->categories->allowsAttachments(
                $instance->subCore->key,
                (string) $case->category,
                $case->sub_category,
            );

        $updates = $case->updates()->with('user')->oldest();
        if (! $canViewAll) {
            $updates->where('visibility', 'public');
        }

        return view($this->area()->view('show'), [
            'case' => $case->load(['user', 'assignedTo', 'attachments']),
            'updates' => $updates->get(),
            'categoryGroups' => $this->categories->categories($instance->subCore->key),
            'websites' => $this->categories->websites($instance->subCore->key),
            'priorities' => $this->area()->priorities,
            'statuses' => $this->area()->statuses,
            'canUpdateCase' => $user->can($prefix.'update-all'),
            'canCloseCase' => $user->can($prefix.'close'),
            'canCommentCase' => $user->can($prefix.'comment-own')
                && $case->status !== 'closed',
            'canChooseVisibility' => $canViewAll,
            'canChangeAssignment' => $canChangeAssignment,
            'allowsAttachments' => $allowsAttachments,
            'revealAssigneeIdentity' => $canViewAll,
            'categoryResolver' => $this->categories,
            'staffUsers' => $canChangeAssignment
                ? User::query()
                    ->eligibleStaff()
                    ->withAuthorizationPermission($prefix.'view-all')
                    ->orderBy('name')
                    ->get()
                : collect(),
            'ownerCandidates' => $canChangeAssignment
                ? User::query()->orderBy('name')->limit(200)->get()
                : collect(),
        ]);
    }

    private function cicUpdate(
        Request $request,
        ShelterCase $case,
        AuditLogger $auditLogger,
        AssignmentAuthorization $assignments,
    ): RedirectResponse {
        $instance = $this->instance($request);
        $prefix = $this->area()->permissionPrefix();
        $this->requireCaseForInstance($case, $instance);
        Gate::authorize('view', $case);
        $assignmentRequested = $request->exists('assigned_to');
        $ownerRequested = $request->exists('user_id');
        $metadataRequested = $request->exists('category')
            || $request->exists('priority')
            || $request->exists('status')
            || $request->exists('sub_category');
        $attachmentRequested = $request->hasFile('screenshots')
            || $request->hasFile('screencast');

        if (! $metadataRequested && ! $assignmentRequested && ! $ownerRequested && ! $attachmentRequested) {
            throw ValidationException::withMessages([
                'case' => __('cases::ui.validation.select_a_case_change_before_submitting'),
            ]);
        }

        if ($metadataRequested) {
            Gate::authorize($prefix.'update-all');
        }
        if ($assignmentRequested || $ownerRequested) {
            $assignments->authorizeChange($request, $request->user(), $case);
            Gate::authorize($prefix.'assign');
        }
        if ($attachmentRequested) {
            Gate::authorize($prefix.'comment-own');
            abort_unless(
                is_string($case->sub_category)
                && $this->categories->allowsAttachments(
                    $instance->subCore->key,
                    (string) $case->category,
                    $case->sub_category,
                ),
                403,
            );
        }

        $rules = [];
        if ($metadataRequested) {
            $rules = [
                'category' => ['required', Rule::in($this->categories->categoryKeys($instance->subCore->key))],
                'sub_category' => ['required', 'string', 'max:64'],
                'affected_website_key' => ['nullable', 'string', 'max:64'],
                'priority' => ['required', Rule::in($this->area()->priorities)],
                'status' => ['required', Rule::in($this->area()->statuses)],
            ];
        }
        if ($assignmentRequested) {
            $rules['assigned_to'] = [
                'sometimes',
                'nullable',
                'integer',
                new EligibleStaffAssignee($prefix.'view-all'),
            ];
        }
        if ($ownerRequested) {
            $rules['user_id'] = [
                'required',
                'integer',
                new EligibleRecordOwner,
            ];
        }
        if ($attachmentRequested) {
            $rules['screenshots'] = ['nullable', 'array', 'max:5'];
            $rules['screenshots.*'] = ['image', 'mimes:jpg,jpeg,png,webp', 'max:3072'];
            $rules['screencast'] = [
                'nullable',
                'file',
                'mimetypes:video/mp4,video/webm',
                'max:'.SecureUploadService::SCREENCAST_MAX_KB,
            ];
        }
        $validated = $request->validate($rules);

        $updates = [];
        if ($metadataRequested) {
            $categoryFields = $this->categories->validateSelection(
                $instance->subCore->key,
                $validated['category'],
                $validated['sub_category'],
                $validated['affected_website_key'] ?? null,
            );
            $requestedStatus = $validated['status'];
            $statusChanged = $requestedStatus !== $case->status;
            $wasTerminal = in_array($case->status, ['resolved', 'closed'], true);
            $willBeTerminal = in_array($requestedStatus, ['resolved', 'closed'], true);
            if ($statusChanged && ($wasTerminal || $willBeTerminal)) {
                Gate::authorize($prefix.'close');
            }

            $updates = [
                'category' => $categoryFields['category'],
                'sub_category' => $categoryFields['sub_category'],
                'affected_website_key' => $categoryFields['affected_website_key'],
                'priority' => $validated['priority'],
                'status' => $requestedStatus,
            ];
            if ($statusChanged) {
                $updates['resolved_at'] = match ($requestedStatus) {
                    'resolved' => $case->resolved_at ?? $case->closed_at ?? now(),
                    'closed' => $case->status === 'resolved'
                        ? $case->resolved_at
                        : null,
                    default => null,
                };
                $updates['closed_at'] = $requestedStatus === 'closed' ? now() : null;
            }
        }
        if ($assignmentRequested) {
            $updates['assigned_to'] = $validated['assigned_to'] ?? null;
        }
        if ($ownerRequested) {
            $updates['user_id'] = (int) $validated['user_id'];
        }

        $storedAttachments = collect();
        if ($attachmentRequested) {
            $storedAttachments = $this->attachments->storeFor(
                $case,
                $instance->subCore->key,
                $request->user(),
                $request->file('screenshots'),
                $request->file('screencast'),
            );
        }

        if ($updates !== []) {
            $case->fill($updates);
            if (! $case->isDirty() && $storedAttachments->isEmpty()) {
                throw ValidationException::withMessages([
                    'case' => __('cases::ui.validation.select_a_case_change_before_submitting'),
                ]);
            }
            if ($case->isDirty()) {
                $case->save();
            }
        } elseif ($storedAttachments->isEmpty()) {
            throw ValidationException::withMessages([
                'case' => __('cases::ui.validation.select_a_case_change_before_submitting'),
            ]);
        }

        $this->notifyStakeholders($case, $request->user(), 'updated', $instance);
        $auditLogger->record("{$this->area()->auditEventPrefix}.updated", $request->user(), $case, [
            'sub_core_key' => $case->sub_core_key,
            'module_key' => $instance->module->key,
            'category' => $case->category,
            'sub_category' => $case->sub_category,
            'priority' => $case->priority,
            'status' => $case->status,
            'assigned_to' => $case->assigned_to,
            'user_id' => $case->user_id,
            'attachments_added' => $storedAttachments->count(),
        ]);

        return redirect()->route($this->area()->showRouteName(), $case)
            ->with('status', __('cases::ui.flash.case_updated'));
    }

    private function cicDestroy(
        Request $request,
        ShelterCase $case,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $instance = $this->instance($request);
        $this->requireCaseForInstance($case, $instance);
        Gate::authorize('delete', $case);
        $actor = request()->user();
        $auditLogger->record("{$this->area()->auditEventPrefix}.deleted", $actor, $case, [
            'sub_core_key' => $case->sub_core_key,
            'module_key' => $instance->module->key,
            'category' => $case->category,
            'priority' => $case->priority,
            'status' => $case->status,
        ]);
        $case->attachments()->each(fn ($attachment) => $attachment->delete());
        $case->delete();

        return redirect()->route($this->area()->indexRouteName())
            ->with('status', __('cases::ui.flash.case_deleted'));
    }

    private function requireCaseForInstance(
        ShelterCase $case,
        ModuleInstanceDefinition $instance,
    ): void {
        abort_unless(
            $case->sub_core_key === $instance->subCore->key,
            404,
        );
    }

    private function notifyStakeholders(
        ShelterCase $case,
        User $actor,
        string $eventLabel,
        ModuleInstanceDefinition $instance,
    ): void {
        $prefix = $this->area()->permissionPrefix();
        $recipients = User::query()
            ->eligibleStaff()
            ->withAuthorizationPermission($prefix.'view-all')
            ->get();
        if ($case->user?->can('view', $case)) {
            $recipients->push($case->user);
        }

        $recipients
            ->unique('id')
            ->reject(fn (User $recipient): bool => $recipient->id === $actor->id)
            ->each(fn (User $recipient) => $recipient->notify(
                new ApesCicCaseUpdatedNotification(
                    $case,
                    $actor,
                    $eventLabel,
                    $instance->subCore->key,
                    $this->area()->showRouteName(),
                ),
            ));
    }

    private function instance(Request $request): ModuleInstanceDefinition
    {
        return $this->moduleContext->pluginInstance('cases');
    }

    private function area(): CasesArea
    {
        $slug = $this->moduleContext->slug()
            ?? throw ValidationException::withMessages([
                'case' => __('cases::ui.validation.missing_module_context_for_cases'),
            ]);

        return CasesArea::forModule($slug);
    }

    private function shelterIndex(): View
    {
        $area = $this->area();
        $user = request()->user();
        Gate::authorize('viewAny', ShelterCase::class);
        $domain = $this->petProfiles->domainForModule($area->moduleSlug);
        $query = ShelterCase::query()
            ->forSubCore($area->moduleSlug)
            ->visibleTo($user, $area->moduleSlug)
            ->whereHas(
                'petProfile',
                static fn ($pets) => $pets->where('service_domain', $domain),
            )
            ->with(['petProfile', 'assignedTo'])
            ->latest();

        $petProfiles = $this->petProfiles->visibleOrdered($user, $domain);

        return view($area->view('index'), [
            'cases' => $query->paginate(20)->fragment('list'),
            'canCreateCase' => $user->can($area->permission('create')),
            'petProfiles' => $petProfiles,
            ...StaffPetCreateReturn::emptySelectViewData(
                $user,
                $petProfiles,
                $area->moduleSlug.'.pet-profiles.create',
                'shelter.pets.index',
                StaffPetCreateReturn::SHELTER_CASES,
            ),
        ]);
    }

    private function shelterStore(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $area = $this->area();
        Gate::authorize('create', ShelterCase::class);
        $selectedPet = $request->validate([
            'pet_profile_id' => ['required', 'integer'],
        ]);
        $domain = $this->petProfiles->domainForModule($area->moduleSlug);
        $pet = $this->petProfiles->findVisibleOrFail(
            $request->user(),
            $domain,
            (int) $selectedPet['pet_profile_id'],
        );
        Gate::authorize('view', $pet);
        Gate::authorize('createShelterCase', $pet);

        $validated = $request->validate([
            'pet_profile_id' => ['required', 'integer'],
            'case_type' => ['required', Rule::in($area->caseTypes)],
            'title' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string'],
        ]);

        $case = ShelterCase::create([
            ...$validated,
            'sub_core_key' => $area->moduleSlug,
            'user_id' => $pet->user_id,
            'status' => 'open',
        ]);

        $this->notifyShelterStakeholders($case, $request->user(), 'created');
        $auditLogger->record("{$area->auditEventPrefix}.created", $request->user(), $case, [
            'case_type' => $case->case_type,
            'status' => $case->status,
        ]);

        return redirect()->route($area->showRouteName(), $case)
            ->with('status', __('cases::ui.flash.case_created'));
    }

    private function shelterShow(
        ShelterCase $case,
        AssignmentAuthorization $assignments,
    ): View {
        $area = $this->area();
        $case = $this->visibleShelterCase($case, request()->user());
        Gate::authorize('view', $case);
        $user = request()->user();
        $prefix = $area->permissionPrefix();
        $canViewAll = $user->can($prefix.'view-all');
        $canChangeAssignment = $assignments->allows($user)
            && $user->can($prefix.'assign');
        $updates = $case->updates()->with('user')->oldest();
        if (! $canViewAll) {
            $updates->where('visibility', 'public');
        }

        return view($area->view('show'), [
            'case' => $case->load(['petProfile', 'assignedTo']),
            'updates' => $updates->get(),
            'caseTypes' => $area->caseTypes,
            'statuses' => $area->statuses,
            'canChangeAssignment' => $canChangeAssignment,
            'canUpdateCase' => $user->can($prefix.'update-all')
                || ($case->user_id === $user->id
                    && $user->can($prefix.'update-own')),
            'canCloseCase' => $user->can($prefix.'close'),
            'canCommentCase' => $user->can($prefix.'comment-own')
                && $case->status !== 'closed',
            'canChooseVisibility' => $canViewAll,
            'staffUsers' => $canChangeAssignment
                ? User::query()
                    ->eligibleStaff()
                    ->withAuthorizationPermission($prefix.'view-all')
                    ->orderBy('name')
                    ->get()
                : collect(),
        ]);
    }

    private function shelterUpdate(
        Request $request,
        ShelterCase $case,
        AuditLogger $auditLogger,
        AssignmentAuthorization $assignments,
    ): RedirectResponse {
        $area = $this->area();
        $case = $this->visibleShelterCase($case, $request->user());
        Gate::authorize('view', $case);
        $metadataFields = ['case_type', 'title', 'details', 'status'];
        $metadataRequested = collect($metadataFields)
            ->contains(fn (string $field): bool => $request->exists($field));
        $assignmentRequested = $request->exists('assigned_to');
        if (! $metadataRequested && ! $assignmentRequested) {
            throw ValidationException::withMessages([
                'case' => __('cases::ui.validation.select_a_case_change_before_submitting'),
            ]);
        }

        $prefix = $area->permissionPrefix();
        $otherMetadataRequested = collect([
            'case_type',
            'title',
            'details',
        ])->contains(fn (string $field): bool => $request->exists($field));
        $closedBoundaryRequested = $request->exists('status')
            && $request->input('status') !== $case->status
            && ($case->status === 'closed'
                || $request->input('status') === 'closed');

        if ($metadataRequested
            && ($otherMetadataRequested || ! $closedBoundaryRequested)) {
            Gate::authorize('update', $case);
        }
        if ($closedBoundaryRequested) {
            Gate::authorize($prefix.'close');
        }
        if ($assignmentRequested) {
            $assignments->authorizeChange($request, $request->user(), $case);
            Gate::authorize($prefix.'assign');
        }

        $rules = [];
        if ($request->exists('case_type')) {
            $rules['case_type'] = ['required', Rule::in($area->caseTypes)];
        }
        if ($request->exists('title')) {
            $rules['title'] = ['required', 'string', 'max:255'];
        }
        if ($request->exists('details')) {
            $rules['details'] = ['nullable', 'string'];
        }
        if ($request->exists('status')) {
            $rules['status'] = ['required', Rule::in($area->statuses)];
        }
        if ($assignmentRequested) {
            $rules['assigned_to'] = [
                'sometimes',
                'nullable',
                'integer',
                new EligibleStaffAssignee($prefix.'view-all'),
            ];
        }
        $validated = $request->validate($rules);

        $updates = collect($metadataFields)
            ->filter(fn (string $field): bool => array_key_exists($field, $validated))
            ->mapWithKeys(fn (string $field): array => [
                $field => $validated[$field],
            ])
            ->all();
        if ($assignmentRequested) {
            $updates['assigned_to'] = isset($validated['assigned_to'])
                ? (int) $validated['assigned_to']
                : null;
        }

        $statusChanged = array_key_exists('status', $updates)
            && $updates['status'] !== $case->status;
        if ($statusChanged && ($case->status === 'closed'
            || $updates['status'] === 'closed')) {
            Gate::authorize($prefix.'close');
        }
        if ($statusChanged) {
            $updates['closed_at'] = $updates['status'] === 'closed'
                ? now()
                : null;
        }

        $case->fill($updates);
        if (! $case->isDirty()) {
            throw ValidationException::withMessages([
                'case' => __('cases::ui.validation.select_a_case_change_before_submitting'),
            ]);
        }
        $case->save();

        $this->notifyShelterStakeholders($case, $request->user(), 'updated');
        $auditLogger->record("{$area->auditEventPrefix}.updated", $request->user(), $case, [
            'sub_core_key' => $case->sub_core_key,
            'module_key' => 'cases',
            'status' => $case->status,
            'assigned_to' => $case->assigned_to,
        ]);

        return redirect()->route($area->showRouteName(), $case)->with('status', __('cases::ui.flash.case_updated'));
    }

    private function visibleShelterCase(ShelterCase $case, User $user): ShelterCase
    {
        $area = $this->area();
        $domain = $this->petProfiles->domainForModule($area->moduleSlug);

        return ShelterCase::query()
            ->forSubCore($area->moduleSlug)
            ->visibleTo($user, $area->moduleSlug)
            ->whereKey($case->getKey())
            ->whereHas(
                'petProfile',
                static fn ($pets) => $pets->where('service_domain', $domain),
            )
            ->firstOrFail();
    }

    private function notifyShelterStakeholders(ShelterCase $case, User $actor, string $eventLabel): void
    {
        $area = $this->area();
        $prefix = $area->permissionPrefix();
        $staffRecipients = User::query()
            ->eligibleStaff()
            ->withAuthorizationPermission($prefix.'view-all')
            ->get();

        if ($case->user?->can('view', $case)) {
            $staffRecipients->push($case->user);
        }

        $recipients = $staffRecipients
            ->unique('id')
            ->reject(fn (User $recipient): bool => $recipient->id === $actor->id);

        foreach ($recipients as $recipient) {
            $recipient->notify(new ShelterCaseUpdatedNotification($case, $actor, $eventLabel));
        }
    }
}
