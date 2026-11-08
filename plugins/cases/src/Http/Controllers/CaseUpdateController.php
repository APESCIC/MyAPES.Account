<?php

namespace Plugins\Cases\Http\Controllers;

use App\Core\Accounts\User;
use App\Core\Extensions\Modules\ModuleContext;
use App\Http\Controllers\Controller;
use App\Modules\ModuleInstanceDefinition;
use App\Core\Attachments\AttachmentFeedback;
use App\Core\Attachments\AttachmentLimits;
use App\Core\Attachments\SupportAttachment;
use App\Services\AuditLogger;
use App\Services\SupportAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Plugins\Cases\CaseAttachmentGate;
use Plugins\Cases\CasesArea;
use Plugins\Cases\Models\CaseUpdate;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Cases\Notifications\ApesCicCaseUpdatedNotification;
use Plugins\Cases\Notifications\ShelterCaseUpdatedNotification;
class CaseUpdateController extends Controller
{
    public function __construct(
        private readonly ModuleContext $moduleContext,
        private readonly CaseAttachmentGate $attachmentGate,
        private readonly SupportAttachmentService $attachments,
    ) {}

    public function store(
        Request $request,
        ShelterCase $case,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $instance = $this->instance($request);
        $area = $this->area();
        $prefix = $area->permissionPrefix();
        if ($area->isShelter()) {
            $case = ShelterCase::query()
                ->forSubCore($area->moduleSlug)
                ->visibleTo($request->user(), $area->moduleSlug)
                ->whereKey($case->getKey())
                ->whereHas('petProfile')
                ->firstOrFail();
        } else {
            abort_unless(
                $case->sub_core_key === $instance->subCore->key,
                404,
            );
        }
        Gate::authorize('view', $case);
        Gate::authorize($prefix.'comment-own');

        if ($case->status === 'closed') {
            throw ValidationException::withMessages([
                'body' => __('cases::ui.validation.reopen_the_case_before_adding_another_update'),
            ]);
        }

        $attachmentRequested = AttachmentLimits::requested($request);
        if ($attachmentRequested) {
            Gate::authorize('create', [SupportAttachment::class, $case]);
            abort_unless($this->allowsAttachments($case, $area), 403);
        }

        $rules = [
            'body' => ['required', 'string'],
            'visibility' => [
                'nullable',
                Rule::in([
                    CaseUpdate::VISIBILITY_PUBLIC,
                    CaseUpdate::VISIBILITY_INTERNAL,
                ]),
            ],
        ];
        if ($attachmentRequested) {
            $rules = [...$rules, ...AttachmentLimits::rules()];
        }
        $validated = $request->validate($rules, AttachmentLimits::messages());
        if ($attachmentRequested) {
            AttachmentLimits::assertSubmissionWithinLimits($request);
        }
        $isStaffViewer = $request->user()->can($prefix.'view-all');
        $visibility = $isStaffViewer
            ? ($validated['visibility'] ?? CaseUpdate::VISIBILITY_PUBLIC)
            : CaseUpdate::VISIBILITY_PUBLIC;
        $storedAttachments = collect();
        if ($attachmentRequested) {
            $storedAttachments = $this->attachments->storeFromRequest(
                $case,
                $case->sub_core_key,
                $request->user(),
                $request,
                $visibility === CaseUpdate::VISIBILITY_INTERNAL
                    ? SupportAttachment::VISIBILITY_INTERNAL
                    : SupportAttachment::VISIBILITY_PUBLIC,
            );
        }
        $update = $case->updates()->create([
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
            'visibility' => $visibility,
        ]);
        $this->attachments->linkTo($storedAttachments, $update);
        if ($visibility === CaseUpdate::VISIBILITY_PUBLIC) {
            $case->touch();
        }

        $recipients = User::query()
            ->eligibleStaff()
            ->withAuthorizationPermission($prefix.'view-all')
            ->get();
        if ($visibility === CaseUpdate::VISIBILITY_PUBLIC
            && $case->user?->can('view', $case)) {
            $recipients->push($case->user);
        }
        if ($area->isShelter()
            && $visibility === CaseUpdate::VISIBILITY_INTERNAL) {
            $recipients = $recipients->reject(
                fn (User $recipient): bool => $recipient->id === $case->user_id,
            );
        }
        $recipients->unique('id')
            ->reject(fn (User $recipient): bool => $recipient->id === $request->user()->id)
            ->each(fn (User $recipient) => $recipient->notify(
                $area->isShelter()
                    ? new ShelterCaseUpdatedNotification(
                        $case,
                        $request->user(),
                        'updated',
                        $storedAttachments->count(),
                    )
                    : new ApesCicCaseUpdatedNotification(
                        $case,
                        $request->user(),
                        'updated',
                        $instance->subCore->key,
                        $area->showRouteName(),
                        $storedAttachments->count(),
                    ),
            ));

        $auditLogger->record("{$area->auditEventPrefix}.update_added", $request->user(), $case, [
            'sub_core_key' => $case->sub_core_key,
            'module_key' => $instance->module->key,
            'visibility' => $visibility,
            ...($attachmentRequested ? ['attachments_added' => $storedAttachments->count()] : []),
        ]);

        return redirect()->route($area->showRouteName(), $case)
            ->with('status', AttachmentFeedback::status(__('cases::ui.flash.case_update_added'), $storedAttachments->count()));
    }

    private function allowsAttachments(ShelterCase $case, CasesArea $area): bool
    {
        return $this->attachmentGate->allows($area, $case->sub_core_key, $case->category, $case->sub_category);
    }

    private function instance(Request $request): ModuleInstanceDefinition
    {
        return $this->moduleContext->pluginInstance('cases');
    }

    private function area(): CasesArea
    {
        $slug = $this->moduleContext->slug()
            ?? throw new InvalidArgumentException('Missing module context for cases.');

        return CasesArea::forModule($slug);
    }
}
