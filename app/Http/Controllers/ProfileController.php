<?php

namespace App\Http\Controllers;

use App\Core\Accounts\PhoneVerificationPinService;
use App\Core\Accounts\StaffProfile;
use App\Rules\Username;
use App\Services\AuditLogger;
use App\Services\AuthorizationProfile;
use App\Services\ContactPreferenceUpdater;
use App\Services\LocalPublicPasswordResetService;
use App\Services\SecureEmailChangeService;
use App\Services\SecureUploadService;
use App\Services\SessionAuthorizationContext;
use App\Services\StaffProfilePhotoResponder;
use App\Services\TotpTwoFactorService;
use App\Services\UkPhoneNumber;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        LocalPublicPasswordResetService $passwordResets,
        SecureEmailChangeService $emailChanges,
        TotpTwoFactorService $twoFactor,
    ): View {
        $user = $request->user();

        if ($this->authorization->hasDirectoryProtectedEligibility($user)) {
            return view('profile.staff-edit', [
                'staffProfile' => $user->staffProfile,
                'teams' => $this->teamOptions(),
            ]);
        }

        $canChangeLocalEmail = $user->isLocalPasswordIdentity();
        $canManageTwoFactor = $user->isLocalPasswordIdentity();
        $canManagePasskeys = $user->isLocalPasswordIdentity();
        $plainRecoveryCodes = $request->session()->get(TotpTwoFactorService::SESSION_PLAIN_RECOVERY_CODES);

        return view('profile.edit', [
            'profile' => $user->profile,
            'preference' => $user->contactPreference,
            'selectedServices' => $user->serviceSelections()->pluck('sub_core_key')->all(),
            'canChangeLocalPassword' => $passwordResets->canChangeOwnPassword($user),
            'canChangeLocalUsername' => $user->isLocalPasswordIdentity(),
            'phonePin' => app(PhoneVerificationPinService::class)->for($user),
            'canChangeLocalEmail' => $canChangeLocalEmail,
            'pendingEmailChange' => $canChangeLocalEmail ? $emailChanges->pendingFor($user) : null,
            'canManageTwoFactor' => $canManageTwoFactor,
            'twoFactorEnabled' => $canManageTwoFactor && $twoFactor->hasEnabledTwoFactor($user),
            'twoFactorPending' => $canManageTwoFactor && $twoFactor->hasPendingEnrolment($user),
            'plainRecoveryCodes' => is_array($plainRecoveryCodes) ? $plainRecoveryCodes : [],
            'canManagePasskeys' => $canManagePasskeys,
            'passkeys' => $canManagePasskeys ? $user->passkeys()->orderByDesc('created_at')->get() : collect(),
        ]);
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
            ->route('profile.edit')
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

        return redirect()->route('profile.edit')->with('status', __('flash.password_updated'));
    }

    public function update(
        Request $request,
        SecureUploadService $secureUploadService,
        AuditLogger $auditLogger,
        ContactPreferenceUpdater $preferences,
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
            'locale' => ['sometimes', 'string', Rule::in(array_keys(config('app.supported_locales', ['en_GB' => 'English (UK)'])))],
        ]);

        if (array_key_exists('locale', $validated)) {
            $request->user()->forceFill(['locale' => $validated['locale']])->save();
        }

        $existingProfile = $request->user()->profile;
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

        $profile = $request->user()->profile()->updateOrCreate(
            ['user_id' => $request->user()->id],
            $payload
        );

        $request->user()->serviceSelections()->whereNotIn('sub_core_key', $validated['services'])->delete();
        foreach ($validated['services'] as $service) {
            $request->user()->serviceSelections()->firstOrCreate(['sub_core_key' => $service]);
        }
        $preferences->update(
            $request->user(),
            $request->user(),
            $onboarding->contactChoices($request),
            'preferences',
        );

        if ($request->hasFile('avatar') && is_string($previousAvatarPath) && $previousAvatarPath !== '' && $previousAvatarPath !== $profile->avatar_path) {
            $secureUploadService->deleteIfPresent($previousAvatarPath);
        }

        $auditLogger->record('profile.updated', $request->user(), $profile, [
            'avatar_updated' => $request->hasFile('avatar'),
        ]);

        return redirect()->route('profile.edit')->with('status', __('flash.profile_updated'));
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
}
