<?php

namespace App\Services;

use App\Core\Accounts\ContactPreference;
use App\Core\Accounts\PhoneVerificationPinService;
use App\Core\Accounts\User;
use App\Core\Accounts\UserProfile;
use Illuminate\Http\Request;

final class AccountSettingsViewData
{
    public function __construct(
        private readonly SecureEmailChangeService $emailChanges,
        private readonly TotpTwoFactorService $twoFactor,
        private readonly LocalPublicPasswordResetService $passwordResets,
        private readonly PhoneVerificationPinService $phonePins,
        private readonly AccountSignedInDevices $signedInDevices,
        private readonly AccountSecurityActivity $securityActivity,
    ) {}

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

        return [
            'canChangeLocalEmail' => $localIdentity,
            'canChangeLocalUsername' => $localIdentity,
            'pendingEmailChange' => $localIdentity ? $this->emailChanges->pendingFor($user) : null,
            'signInUsesDirectory' => ! $localIdentity,
            'passkeyCount' => $localIdentity ? $user->passkeys()->count() : 0,
            'twoFactorEnabled' => $localIdentity && $this->twoFactor->hasEnabledTwoFactor($user),
        ];
    }

    /**
     * View data for Account → Profile (personal and contact details).
     *
     * @return array{
     *     profile: UserProfile|null,
     *     preference: ContactPreference|null,
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
        $plainRecoveryCodes = $request->session()->get(TotpTwoFactorService::SESSION_PLAIN_RECOVERY_CODES);

        return [
            'canChangeLocalPassword' => $this->passwordResets->canChangeOwnPassword($user),
            'canChangeLocalUsername' => $localIdentity,
            'phonePin' => $localIdentity ? $this->phonePins->for($user) : null,
            'canManageTwoFactor' => $localIdentity,
            'twoFactorEnabled' => $localIdentity && $this->twoFactor->hasEnabledTwoFactor($user),
            'twoFactorPending' => $localIdentity && $this->twoFactor->hasPendingEnrolment($user),
            'plainRecoveryCodes' => is_array($plainRecoveryCodes) ? $plainRecoveryCodes : [],
            'canManagePasskeys' => $localIdentity,
            'passkeys' => $localIdentity
                ? $user->passkeys()->orderByDesc('created_at')->get()
                : collect(),
            'signedInDevices' => $this->signedInDevices->forUser($user, $request),
            'canListMultipleSignedInDevices' => $this->signedInDevices->canListMultipleDevices(),
            'securityActivity' => $this->securityActivity->recentFor($user),
        ];
    }
}
