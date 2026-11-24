<?php

namespace App\Services;

use App\Core\Accounts\PhoneVerificationPinService;
use App\Core\Accounts\StaffProfile;
use App\Core\Accounts\User;
use App\Core\Accounts\UserContactPreference;
use App\Core\Accounts\UserProfile;
use App\Core\Push\ProfileNotificationTypeCatalogue;
use Illuminate\Http\Request;

final class AccountSettingsViewData
{
    public function __construct(
        private readonly AuthorizationProfile $authorization,
        private readonly SecureEmailChangeService $emailChanges,
        private readonly TotpTwoFactorService $twoFactor,
        private readonly LocalPublicPasswordResetService $passwordResets,
        private readonly PhoneVerificationPinService $phonePins,
        private readonly AccountSignedInDevices $signedInDevices,
        private readonly AccountSecurityActivity $securityActivity,
        private readonly ProfileNotificationTypeCatalogue $notificationCatalogue,
    ) {}

    public function usesStaffDirectoryProfile(User $user): bool
    {
        return $this->authorization->hasDirectoryProtectedEligibility($user);
    }

    /**
     * View data for Account → login details (email, username, sign-in summary, appearance).
     *
     * @return array{
     *     canChangeLocalEmail: bool,
     *     canChangeLocalUsername: bool,
     *     pendingEmailChange: mixed,
     *     signInUsesDirectory: bool,
     *     passkeyCount: int,
     *     twoFactorEnabled: bool,
     * }
     */
    public function accountTab(User $user): array
    {
        $localIdentity = $user->isLocalPasswordIdentity();
        $staffDirectory = $this->usesStaffDirectoryProfile($user);
        $directoryStaffCredentials = $this->canManageDirectoryStaffCredentials($user);

        return [
            'usesStaffDirectoryProfile' => $staffDirectory,
            'canChangeLocalEmail' => $localIdentity && ! $staffDirectory,
            'canChangeLocalUsername' => $localIdentity && ! $staffDirectory,
            'pendingEmailChange' => $localIdentity && ! $staffDirectory ? $this->emailChanges->pendingFor($user) : null,
            'signInUsesDirectory' => ! $localIdentity,
            'directoryStaffSecuritySummary' => $directoryStaffCredentials,
            'passkeyCount' => ($localIdentity || $directoryStaffCredentials)
                ? $user->passkeys()->count()
                : 0,
            'twoFactorEnabled' => $localIdentity && $this->twoFactor->hasEnabledTwoFactor($user),
        ];
    }

    /**
     * @return array{staffProfile: \App\Core\Accounts\StaffProfile|null, teams: array<string, string>}
     */
    public function staffProfileTab(User $user): array
    {
        return [
            'staffProfile' => $user->staffProfile,
            'teams' => $this->staffTeamOptions(),
        ];
    }

    /**
     * View data for Account → Profile (personal and contact details).
     *
     * @return array{
     *     profile: UserProfile|null,
     *     preference: UserContactPreference|null,
     *     selectedServices: list<string>,
     * }
     */
    public function profileTab(User $user): array
    {
        return [
            'profile' => $user->profile,
            'preference' => $user->contactPreference,
            'selectedServices' => $user->serviceSelections()->pluck('sub_core_key')->all(),
        ];
    }

    /**
     * View data for Account → Security.
     *
     * @return array{
     *     canChangeLocalPassword: bool,
     *     canChangeLocalUsername: bool,
     *     phonePin: mixed,
     *     canManageTwoFactor: bool,
     *     twoFactorEnabled: bool,
     *     twoFactorPending: bool,
     *     plainRecoveryCodes: list<string>,
     *     canManagePasskeys: bool,
     *     passkeys: \Illuminate\Support\Collection,
     *     signedInDevices: list<array<string, mixed>>,
     *     canListMultipleSignedInDevices: bool,
     *     securityActivity: list<array<string, mixed>>,
     * }
     */
    public function securityTab(User $user, Request $request): array
    {
        $localIdentity = $user->isLocalPasswordIdentity();
        $directoryStaffCredentials = $this->canManageDirectoryStaffCredentials($user);
        $canManagePasskeys = $localIdentity || $directoryStaffCredentials;
        $canManagePhonePin = $localIdentity || $directoryStaffCredentials;
        $plainRecoveryCodes = $request->session()->get(TotpTwoFactorService::SESSION_PLAIN_RECOVERY_CODES);

        return [
            'usesStaffDirectoryProfile' => $this->usesStaffDirectoryProfile($user),
            'canChangeLocalPassword' => $this->passwordResets->canChangeOwnPassword($user),
            'canChangeLocalUsername' => $localIdentity && ! $this->usesStaffDirectoryProfile($user),
            'phonePin' => $canManagePhonePin ? $this->phonePins->for($user) : null,
            'canManagePhonePin' => $canManagePhonePin,
            'canManageTwoFactor' => $localIdentity && ! $this->usesStaffDirectoryProfile($user),
            'twoFactorEnabled' => $localIdentity && $this->twoFactor->hasEnabledTwoFactor($user),
            'twoFactorPending' => $localIdentity && $this->twoFactor->hasPendingEnrolment($user),
            'plainRecoveryCodes' => is_array($plainRecoveryCodes) ? $plainRecoveryCodes : [],
            'canManagePasskeys' => $canManagePasskeys,
            'passkeys' => $canManagePasskeys
                ? $user->passkeys()->orderByDesc('created_at')->get()
                : collect(),
            'signedInDevices' => $this->signedInDevices->forUser($user, $request),
            'canListMultipleSignedInDevices' => $this->signedInDevices->canListMultipleDevices(),
            'securityActivity' => $this->securityActivity->recentFor($user),
        ];
    }

    /**
     * View data for Account → Privacy.
     *
     * @return array{
     *     preference: UserContactPreference|null,
     *     policyVersion: string,
     *     notificationsManageUrl: string,
     * }
     */
    public function privacyTab(User $user): array
    {
        return [
            'preference' => $user->contactPreference,
            'policyVersion' => (string) config('myapes.consent.policy_version'),
            'notificationsManageUrl' => route('account.privacy').'#notifications',
        ];
    }

    public function canManageDirectoryStaffCredentials(User $user): bool
    {
        return $this->usesStaffDirectoryProfile($user)
            && ($user->hasDirectoryIdentity() || $user->isCloudronIdentity());
    }

    /**
     * @return array<string, string>
     */
    private function staffTeamOptions(): array
    {
        return [
            StaffProfile::TEAM_APES_CIC => 'APES CIC',
            StaffProfile::TEAM_SHELTER_RESCUE => 'APES Shelter and Rescue',
            StaffProfile::TEAM_PET_CARE_CLINIC => 'APES Pet Care Clinic',
            StaffProfile::TEAM_OPERATIONS => 'Operations',
        ];
    }
}
