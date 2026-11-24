<?php

namespace App\Services;

use App\Core\Accounts\User;

final class AccountSettingsViewData
{
    public function __construct(
        private readonly SecureEmailChangeService $emailChanges,
        private readonly TotpTwoFactorService $twoFactor,
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
     *     profile: \App\Core\Accounts\UserProfile|null,
     *     preference: \App\Core\Accounts\ContactPreference|null,
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
}