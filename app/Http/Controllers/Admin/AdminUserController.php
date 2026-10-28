<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\ModuleRegistry;
use App\Core\Accounts\AuditLog;
use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\StaffProfile;
use App\Core\Accounts\User;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OnboardingController;
use App\Services\AuditLogger;
use App\Services\AuthorizationMutationService;
use App\Services\AuthorizationProfile;
use App\Services\ContactPreferenceUpdater;
use App\Services\LocalPublicPasswordResetService;
use App\Services\PendingFirstLoginChaseService;
use App\Services\SecureUploadService;
use App\Services\StaffProfilePhotoResponder;
use App\Services\UkPhoneNumber;
use App\Services\VerificationResendService;
use App\Support\JobRoleCapabilityPacks;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminUserController extends Controller
{
    public function index(
        Request $request,
        AuthorizationProfile $profile,
        LocalPublicPasswordResetService $passwordResets,
    ): View {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'account_type' => ['nullable', Rule::in(['public', 'staff'])],
            'identity_type' => [
                'nullable',
                Rule::in([
                    User::IDENTITY_LOCAL,
                    User::IDENTITY_CLOUDRON_OIDC,
                ]),
            ],
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
            'protected_role' => [
                'nullable',
                Rule::in($profile->protectedRolesByPrecedence()),
            ],
        ]);
        $query = User::query()->with([
            'roles' => fn ($query) => $query->orderBy('name'),
        ]);

        if (isset($filters['q'])) {
            $search = trim($filters['q']);
            $query->where(static function ($query) use ($search): void {
                $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (isset($filters['identity_type'])) {
            $query->where('identity_type', $filters['identity_type']);
        }

        $staffRoles = $profile->directoryProtectedRoles();
        if (($filters['account_type'] ?? null) === 'staff') {
            $query->whereHas(
                'roles',
                static fn ($query) => $query
                    ->where('roles.guard_name', 'web')
                    ->whereIn('roles.name', $staffRoles),
            );
        } elseif (($filters['account_type'] ?? null) === 'public') {
            $query->whereDoesntHave(
                'roles',
                static fn ($query) => $query
                    ->where('roles.guard_name', 'web')
                    ->whereIn('roles.name', $staffRoles),
            );
        }

        if (($filters['status'] ?? null) === 'active') {
            $query->whereNull('suspended_at');
        } elseif (($filters['status'] ?? null) === 'suspended') {
            $query->whereNotNull('suspended_at');
        }

        if (isset($filters['protected_role'])) {
            $role = $filters['protected_role'];
            $query->whereHas(
                'roles',
                static fn ($query) => $query
                    ->where('roles.guard_name', 'web')
                    ->where('roles.name', $role),
            );
        }

        $users = $query
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();
        $actor = $request->user();
        $resettableUserIds = [];

        if ($actor instanceof User && $actor->can('admin.users.manage')) {
            $resettableUserIds = $users->getCollection()
                ->filter(
                    static fn (User $user): bool => $passwordResets->canReset(
                        $actor,
                        $user,
                    ),
                )
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
        }

        return view('admin.users.index', [
            'users' => $users,
            'filters' => $filters,
            'identityTypes' => [
                User::IDENTITY_LOCAL => 'Local',
                User::IDENTITY_CLOUDRON_OIDC => 'Cloudron OIDC',
            ],
            'protectedRoles' => $profile->protectedRolesByPrecedence(),
            'authorizationProfile' => $profile,
            'resettableUserIds' => $resettableUserIds,
        ]);
    }

    public function show(
        Request $request,
        string $user,
        AuthorizationMutationService $mutations,
        LocalPublicPasswordResetService $passwordResets,
        PendingFirstLoginChaseService $pendingFirstLoginChase,
        ModuleRegistry $modules,
        VerificationResendService $verificationResends,
    ): View {
        Gate::authorize('admin.users.view');
        $managedUser = User::query()->findOrFail($user);
        $managedUser->load([
            'profile',
            'staffProfile',
            'contactPreference',
            'serviceSelections',
            'permissions' => fn ($query) => $query->orderBy('name'),
            'permissionSources' => fn ($query) => $query
                ->with(['permission', 'actor'])
                ->orderBy('permission_id')
                ->orderBy('source'),
            'roles' => fn ($query) => $query
                ->with('permissions')
                ->orderBy('name'),
            'roleSources' => fn ($query) => $query
                ->with(['role', 'directoryGroup'])
                ->orderBy('role_id')
                ->orderBy('source'),
        ]);
        $permissions = $managedUser->permissions
            ->merge($managedUser->roles->flatMap->permissions)
            ->unique('id')
            ->sortBy('name')
            ->values();
        $permissionNames = $permissions->pluck('name')->all();
        $packDefinitions = JobRoleCapabilityPacks::definitions($modules);
        $packStates = [];

        foreach (array_keys($packDefinitions) as $packKey) {
            $packStates[$packKey] = JobRoleCapabilityPacks::state(
                $packKey,
                $permissionNames,
                $modules,
            );
        }

        $auditContextKeys = [
            'actor_id',
            'target_user_id',
            'role_id',
            'role_ids',
            'role_count',
            'permission_count',
            'affected_user_count',
            'group_id',
            'mapping_id',
            'matched_user_count',
            'changed_user_count',
            'action',
            'source_key',
            'route_name',
            'method',
            'reason_code',
            'reason_length',
            'granted_count',
            'revoked_count',
        ];
        $auditHistory = AuditLog::query()
            ->where('auditable_type', $managedUser->getMorphClass())
            ->where('auditable_id', $managedUser->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(static fn (AuditLog $audit): array => [
                'event' => $audit->event,
                'actor_id' => $audit->user_id,
                'created_at' => $audit->created_at,
                'context' => Arr::only(
                    is_array($audit->context) ? $audit->context : [],
                    $auditContextKeys,
                ),
            ]);

        return view('admin.users.show', [
            'managedUser' => $managedUser,
            'permissions' => $permissions,
            'packDefinitions' => $packDefinitions,
            'packStates' => $packStates,
            'customRoles' => Role::query()
                ->where('guard_name', 'web')
                ->where('is_protected', false)
                ->orderBy('name')
                ->get(),
            'localRoleIds' => $managedUser->roleSources
                ->where('source', RoleSource::SOURCE_LOCAL)
                ->pluck('role_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
            'auditHistory' => $auditHistory,
            'canManageTarget' => $mutations->canManageTarget(
                $request->user(),
                $managedUser,
            ),
            'canResetLocalPassword' => $request->user() instanceof User
                && $passwordResets->canReset($request->user(), $managedUser),
            'canChasePendingFirstLogin' => $request->user() instanceof User
                && $pendingFirstLoginChase->canChase(
                    $request->user(),
                    $managedUser,
                ),
            'canResendVerification' => $request->user() instanceof User
                && $verificationResends->canResend($request->user(), $managedUser),
            'identityLabel' => match ($managedUser->identity_type) {
                User::IDENTITY_LOCAL => 'Local',
                User::IDENTITY_CLOUDRON_OIDC => 'Cloudron OIDC',
                User::IDENTITY_HYBRID => 'Hybrid',
                default => 'Unknown',
            },
            'isStaffAccount' => app(AuthorizationProfile::class)
                ->hasDirectoryProtectedEligibility($managedUser),
            'teams' => [
                StaffProfile::TEAM_APES_CIC => 'APES CIC',
                StaffProfile::TEAM_SHELTER_RESCUE => 'APES Shelter and Rescue',
                StaffProfile::TEAM_PET_CARE_CLINIC => 'APES Pet Care Clinic',
                StaffProfile::TEAM_OPERATIONS => 'Operations',
            ],
            'selectedServices' => $managedUser->serviceSelections
                ->pluck('sub_core_key')
                ->all(),
            'preference' => $managedUser->contactPreference,
            'profile' => $managedUser->profile,
            'staffProfile' => $managedUser->staffProfile,
        ]);
    }

    public function updateRoles(
        Request $request,
        string $user,
        AuthorizationMutationService $mutations,
    ): RedirectResponse {
        Gate::authorize('admin.users.manage');
        $managedUser = User::query()->findOrFail($user);
        $validated = $request->validate([
            'roles' => ['sometimes', 'array'],
            'roles.*' => [
                'integer',
                'distinct',
                Rule::exists('roles', 'id')->where('guard_name', 'web'),
            ],
        ]);
        $roleIds = array_map(
            'intval',
            $validated['roles'] ?? [],
        );
        $roles = Role::query()->whereKey($roleIds)->get()->all();

        try {
            $mutations->synchronizeLocalRoles(
                $managedUser,
                $roles,
                $request->user(),
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors([
                'authorization' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.users.show', $user)
            ->with('status', __('admin.flash.local_role_assignments_updated'));
    }

    public function suspend(
        Request $request,
        string $user,
        AuthorizationMutationService $mutations,
    ): RedirectResponse {
        Gate::authorize('admin.users.manage');
        $managedUser = User::query()->findOrFail($user);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'confirm_suspend' => ['accepted'],
        ]);

        try {
            $mutations->suspend(
                $managedUser,
                $request->user(),
                $validated['reason'],
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors([
                'authorization' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.users.show', $user)
            ->with('status', __('admin.flash.user_suspended'));
    }

    public function reactivate(
        Request $request,
        string $user,
        AuthorizationMutationService $mutations,
    ): RedirectResponse {
        Gate::authorize('admin.users.manage');
        $managedUser = User::query()->findOrFail($user);

        try {
            $mutations->reactivate($managedUser, $request->user());
        } catch (DomainException $exception) {
            return back()->withErrors([
                'authorization' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.users.show', $user)
            ->with('status', __('admin.flash.user_reactivated'));
    }

    public function updateProfile(
        Request $request,
        string $user,
        AuthorizationProfile $profile,
        AuthorizationMutationService $mutations,
        SecureUploadService $secureUploadService,
        AuditLogger $auditLogger,
        ContactPreferenceUpdater $preferences,
        OnboardingController $onboarding,
        UkPhoneNumber $phones,
    ): RedirectResponse {
        Gate::authorize('admin.users.manage');
        $managedUser = User::query()->findOrFail($user);
        abort_unless($mutations->canManageTarget($request->user(), $managedUser), 403);
        abort_if($profile->hasDirectoryProtectedEligibility($managedUser), 403);

        $onboarding->normalizePhones($request, $phones);
        $validated = $request->validate([
            'preferred_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'organization' => ['nullable', 'string', 'max:255'],
            'support_needs' => ['nullable', 'string'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'town_city' => ['required', 'string', 'max:255'],
            'county' => ['nullable', 'string', 'max:255'],
            'postcode' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z]{1,2}\d[A-Za-z\d]?\s*\d[A-Za-z]{2}$/'],
            'mobile_number' => ['required', 'string', 'max:32', 'regex:/^\+44\d{9,10}$/'],
            'landline_number' => ['nullable', 'string', 'max:32', 'regex:/^\+44\d{9,10}$/'],
            'whatsapp_number' => ['nullable', 'string', 'max:32', 'regex:/^\+44\d{9,10}$/'],
            'telegram_username' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]{5,32}$/'],
            'services' => ['required', 'array', 'min:1'],
            'services.*' => ['string', Rule::in(['apes-cic', 'shelter-rescue', 'pet-care-clinic'])],
            'contact_preferences_confirmed' => ['accepted'],
        ]);

        $existingProfile = $managedUser->profile;
        $previousAvatarPath = $existingProfile?->avatar_path;
        $payload = [
            'preferred_name' => $validated['preferred_name'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'organization' => $validated['organization'] ?? null,
            'support_needs' => $validated['support_needs'] ?? null,
        ] + $onboarding->profilePayload($validated);

        if ($request->hasFile('avatar')) {
            $payload['avatar_path'] = $secureUploadService->storeImage(
                $request->file('avatar'),
                'avatars',
                'avatar',
            );
        }

        $userProfile = $managedUser->profile()->updateOrCreate(
            ['user_id' => $managedUser->id],
            $payload,
        );
        $managedUser->serviceSelections()->whereNotIn('sub_core_key', $validated['services'])->delete();
        foreach ($validated['services'] as $service) {
            $managedUser->serviceSelections()->firstOrCreate(['sub_core_key' => $service]);
        }
        $preferences->update(
            $managedUser,
            $request->user(),
            $onboarding->contactChoices($request),
            'preferences',
        );

        if ($request->hasFile('avatar') && is_string($previousAvatarPath) && $previousAvatarPath !== '' && $previousAvatarPath !== $userProfile->avatar_path) {
            $secureUploadService->deleteIfPresent($previousAvatarPath);
        }

        $auditLogger->record('profile.updated', $request->user(), $userProfile, [
            'target_user_id' => $managedUser->id,
            'avatar_updated' => $request->hasFile('avatar'),
        ]);

        return redirect()
            ->route('admin.users.show', $managedUser)
            ->with('status', __('admin.flash.public_profile_updated'));
    }

    public function updateStaffProfile(
        Request $request,
        string $user,
        AuthorizationProfile $profile,
        AuthorizationMutationService $mutations,
        SecureUploadService $secureUploadService,
        AuditLogger $auditLogger,
        UkPhoneNumber $phones,
    ): RedirectResponse {
        Gate::authorize('admin.users.manage');
        $managedUser = User::query()->findOrFail($user);
        abort_unless($mutations->canManageTarget($request->user(), $managedUser), 403);
        abort_unless($profile->hasDirectoryProtectedEligibility($managedUser), 403);

        $request->merge([
            'work_phone' => $phones->normalize($request->input('work_phone'), 'work_phone', false),
        ]);
        $validated = $request->validate([
            'job_title' => ['nullable', 'string', 'max:255'],
            'team' => ['nullable', 'string', Rule::in(StaffProfile::teams())],
            'work_phone' => ['nullable', 'string', 'max:32', 'regex:/^\+44\d{9,10}$/'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ]);

        $existing = $managedUser->staffProfile;
        $previousPhotoPath = $existing?->photo_path;
        $payload = [
            'job_title' => $validated['job_title'] ?? null,
            'team' => $validated['team'] ?? null,
            'work_phone' => $validated['work_phone'] ?? null,
        ];

        if ($request->hasFile('photo')) {
            $payload['photo_path'] = $secureUploadService->storeImage(
                $request->file('photo'),
                'staff-photos',
                'photo',
            );
        }

        $staffProfile = $managedUser->staffProfile()->updateOrCreate(
            ['user_id' => $managedUser->id],
            $payload,
        );

        if (
            $request->hasFile('photo')
            && is_string($previousPhotoPath)
            && $previousPhotoPath !== ''
            && $previousPhotoPath !== $staffProfile->photo_path
        ) {
            $secureUploadService->deleteIfPresent($previousPhotoPath);
        }

        $auditLogger->record('staff_profile.updated', $request->user(), $staffProfile, [
            'target_user_id' => $managedUser->id,
            'photo_updated' => $request->hasFile('photo'),
        ]);

        return redirect()
            ->route('admin.users.show', $managedUser)
            ->with('status', __('admin.flash.staff_profile_updated'));
    }

    public function staffPhoto(
        string $user,
        StaffProfilePhotoResponder $photos,
    ): StreamedResponse {
        Gate::authorize('admin.users.view');
        $managedUser = User::query()->findOrFail($user);

        return $photos->response($managedUser->staffProfile);
    }

    public function resetLocalPassword(
        Request $request,
        string $user,
        LocalPublicPasswordResetService $passwordResets,
    ): RedirectResponse {
        Gate::authorize('admin.users.manage');
        $managedUser = User::query()->findOrFail($user);
        $request->validate([
            'confirm' => ['accepted'],
        ]);

        try {
            $temporaryPassword = $passwordResets->reset(
                $request->user(),
                $managedUser,
            );
        } catch (DomainException $exception) {
            abort(403, $exception->getMessage());
        }

        return redirect()
            ->route('admin.users.show', $managedUser)
            ->with(
                'status',
                __('admin.flash.temporary_password_generated'),
            )
            ->with('temporary_password', $temporaryPassword);
    }

    public function chasePendingFirstLogin(
        Request $request,
        string $user,
        PendingFirstLoginChaseService $chase,
    ): RedirectResponse {
        Gate::authorize('admin.users.manage');
        $managedUser = User::query()->findOrFail($user);
        $request->validate([
            'confirm_chase' => ['accepted'],
        ]);

        try {
            $chase->chase($request->user(), $managedUser);
        } catch (DomainException $exception) {
            abort(403, $exception->getMessage());
        }

        return redirect()
            ->route('admin.users.show', $managedUser)
            ->with(
                'status',
                __('admin.flash.staff_login_reminder_sent'),
            );
    }
}
