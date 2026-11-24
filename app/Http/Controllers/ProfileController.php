<?php

namespace App\Http\Controllers;

use App\Core\Accounts\StaffProfile;
use App\Core\Accounts\User;
use App\Core\Push\ProfileNotificationTypeCatalogue;
use App\Core\Push\UserNotificationSetting;
use App\Core\Qa\QaLogin;
use App\Http\Middleware\EnsureRecentStepUp;
use App\Rules\Username;
use App\Services\AuditLogger;
use App\Services\AuthorizationProfile;
use App\Services\LocalPublicPasswordResetService;
use App\Services\SecureUploadService;
use App\Services\SessionAuthorizationContext;
use App\Services\StaffProfilePhotoResponder;
use App\Services\UkPhoneNumber;
use App\Support\AccountSecurityRedirect;
use App\Support\PersonName;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    public function __construct(
        private readonly AuthorizationProfile $authorization,
        private readonly StaffProfilePhotoResponder $staffPhotos,
    ) {}

    public function edit(
        Request $request,
        ProfileNotificationTypeCatalogue $notifications,
    ): View {
        $user = $request->user();

        $notificationView = $this->notificationViewData($user, $notifications);

        if ($this->authorization->hasDirectoryProtectedEligibility($user)) {
            return view('profile.staff-edit', [
                'staffProfile' => $user->staffProfile,
                'teams' => $this->teamOptions(),
                ...$notificationView,
            ]);
        }

        return view('profile.edit', $notificationView);
    }

    /**
     * @return array{
     *     notificationTypes: list<array<string, mixed>>,
     *     notificationDivisions: list<array<string, mixed>>,
     *     notificationDevices: list<array<string, mixed>>,
     *     notificationSettings: UserNotificationSetting|null,
     * }
     */
    private function notificationViewData(User $user, ProfileNotificationTypeCatalogue $catalogue): array
    {
        return [
            'notificationTypes' => $catalogue->typesFor($user),
            'notificationDivisions' => $catalogue->divisionsFor($user),
            'notificationDevices' => $catalogue->devicesFor($user),
            'notificationSettings' => $user->notificationSetting,
        ];
    }

    public function updateUsername(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->isLocalPasswordIdentity()) {
            abort(403);
        }

        $request->merge([
            'username' => Str::lower(trim((string) $request->input('username'))),
        ]);

        $validated = $request->validate([
            'username' => ['required', 'string', new Username(ignoreUserId: $user->id)],
        ]);

        $previous = $user->username;
        $user->forceFill(['username' => $validated['username']])->save();

        $auditLogger->record('profile.username_updated', $user, $user, [
            'previous_username' => $previous,
            'username' => $user->username,
        ]);

        return redirect()
            ->route('account.show')
            ->withFragment('username')
            ->with('status', __('flash.username_updated'));
    }

    public function updatePassword(
        Request $request,
        LocalPublicPasswordResetService $passwordResets,
        SessionAuthorizationContext $authorizationContext,
    ): RedirectResponse {
        $user = $request->user();

        if (! $passwordResets->canChangeOwnPassword($user)) {
            abort(403);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        try {
            $passwordResets->changeOwnPassword($user, $validated['password']);
        } catch (DomainException $exception) {
            abort(403, $exception->getMessage());
        }

        $request->session()->regenerate();
        $authorizationContext->recordPassword($request, $user);

        return redirect()
            ->to(AccountSecurityRedirect::security('password'))
            ->with('status', __('flash.password_updated'));
    }

    public function update(
        Request $request,
        SecureUploadService $secureUploadService,
        AuditLogger $auditLogger,
        OnboardingController $onboarding,
        UkPhoneNumber $phones,
    ): RedirectResponse {
        if ($this->authorization->hasDirectoryProtectedEligibility($request->user())) {
            return $this->updateStaffProfile(
                $request,
                $secureUploadService,
                $auditLogger,
                $phones,
            );
        }

        $user = $request->user();
        $onboarding->normalizePhones($request, $phones);
        $validated = $onboarding->validatePublicProfileUpdate($request);

        $existingProfile = $user->profile;
        $existingDob = $existingProfile?->date_of_birth?->format('Y-m-d');
        $newDob = $validated['date_of_birth'] ?? null;
        $dobChanged = is_string($newDob)
            && $newDob !== ''
            && $newDob !== $existingDob;

        if ($dobChanged && $this->stepUpConfirmationRequired($request, $user)) {
            $request->session()->put('url.intended', $request->fullUrl());

            return $this->redirectToStepUp($request, $user);
        }

        if (array_key_exists('locale', $validated)) {
            $user->forceFill(['locale' => $validated['locale']])->save();
        }

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
                'avatar'
            );
        }

        $profile = $user->profile()->updateOrCreate(
            ['user_id' => $user->id],
            $payload
        );

        $user->forceFill([
            'name' => PersonName::display(
                $validated['first_name'],
                $validated['last_name'],
                $user->name,
            ),
        ])->save();

        $user->serviceSelections()->whereNotIn('sub_core_key', $validated['services'])->delete();
        foreach ($validated['services'] as $service) {
            $user->serviceSelections()->firstOrCreate(['sub_core_key' => $service]);
        }
        if ($request->hasFile('avatar') && is_string($previousAvatarPath) && $previousAvatarPath !== '' && $previousAvatarPath !== $profile->avatar_path) {
            $secureUploadService->deleteIfPresent($previousAvatarPath);
        }

        if ($dobChanged) {
            $auditLogger->record('profile.date_of_birth_updated', $user, $profile, [
                'previous_date_of_birth' => $existingDob,
                'date_of_birth' => $newDob,
            ]);
        }

        $auditLogger->record('profile.updated', $user, $profile, [
            'avatar_updated' => $request->hasFile('avatar'),
            'date_of_birth_changed' => $dobChanged,
        ]);

        return redirect()->route('account.profile')->with('status', __('flash.profile_updated'));
    }

    public function staffPhoto(Request $request): StreamedResponse
    {
        abort_unless(
            $this->authorization->hasDirectoryProtectedEligibility($request->user()),
            404,
        );

        return $this->staffPhotos->response($request->user()->staffProfile);
    }

    private function updateStaffProfile(
        Request $request,
        SecureUploadService $secureUploadService,
        AuditLogger $auditLogger,
        UkPhoneNumber $phones,
    ): RedirectResponse {
        $request->merge([
            'work_phone' => $phones->normalize($request->input('work_phone'), 'work_phone', false),
        ]);
        $validated = $request->validate([
            'job_title' => ['nullable', 'string', 'max:255'],
            'team' => ['nullable', 'string', Rule::in(StaffProfile::teams())],
            'work_phone' => ['nullable', 'string', 'max:32', 'regex:/^\+44\d{9,10}$/'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'locale' => ['sometimes', 'string', Rule::in(array_keys(config('app.supported_locales', ['en_GB' => 'English (UK)'])))],
        ]);

        $user = $request->user();

        if (array_key_exists('locale', $validated)) {
            $user->forceFill(['locale' => $validated['locale']])->save();
        }

        $existing = $user->staffProfile;
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

        $staffProfile = $user->staffProfile()->updateOrCreate(
            ['user_id' => $user->id],
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

        $auditLogger->record('staff_profile.updated', $user, $staffProfile, [
            'photo_updated' => $request->hasFile('photo'),
        ]);

        return redirect()->route('profile.edit')->with('status', __('flash.staff_profile_updated'));
    }

    /**
     * @return array<string, string>
     */
    private function teamOptions(): array
    {
        return [
            StaffProfile::TEAM_APES_CIC => 'APES CIC',
            StaffProfile::TEAM_SHELTER_RESCUE => 'APES Shelter and Rescue',
            StaffProfile::TEAM_PET_CARE_CLINIC => 'APES Pet Care Clinic',
            StaffProfile::TEAM_OPERATIONS => 'Operations',
        ];
    }

    private function stepUpConfirmationRequired(Request $request, User $user): bool
    {
        $timeout = (int) config('auth.password_timeout', 10800);
        $confirmedAt = Date::now()->unix() - $request->session()->get('auth.password_confirmed_at', 0);

        return $confirmedAt > $timeout;
    }

    private function redirectToStepUp(Request $request, User $user): RedirectResponse
    {
        if (
            QaLogin::enabled()
            && QaLogin::profileFor($user) !== null
        ) {
            $request->session()->flash(QaLogin::CONFIRM, true);

            return redirect()->guest(route('password.confirm'));
        }

        if ($user->hasDirectoryIdentity()) {
            $request->session()->put(EnsureRecentStepUp::OIDC_STEP_UP_SESSION_KEY, [
                'user_id' => $user->getKey(),
                'oidc_sub' => $user->oidc_sub,
            ]);

            return redirect()->guest(route('staff.auth.step-up'));
        }

        return redirect()->guest(route('password.confirm'));
    }
}
