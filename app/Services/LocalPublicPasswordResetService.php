<?php

namespace App\Services;

use App\Core\Accounts\User;
use App\Notifications\Auth\PasswordChangedNotification;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LocalPublicPasswordResetService
{
    public function __construct(
        private readonly AuthorizationProfile $profile,
        private readonly AuthorizationMutationService $mutations,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function canReset(User $actor, User $target): bool
    {
        return $this->refusalMessage($actor, $target) === null;
    }

    public function isEligibleForGuestReset(?User $user): bool
    {
        return $this->isLocalPublicPasswordAccount($user);
    }

    public function canChangeOwnPassword(?User $user): bool
    {
        if ($this->isLocalPublicPasswordAccount($user)) {
            return true;
        }

        return $user !== null
            && $user->isLocalPasswordIdentity()
            && $this->profile->hasDirectoryProtectedEligibility($user);
    }

    public function completeGuestReset(User $user, string $password): void
    {
        if (! $this->isEligibleForGuestReset($user)) {
            throw new DomainException(
                'Only local public accounts can reset a password here. Directory and Cloudron accounts stay on Cloudron.',
            );
        }

        $this->applyOwnPassword(
            $user,
            $password,
            'auth.public_local_password_reset',
            'guest_reset_local_public_password',
        );
    }

    public function changeOwnPassword(User $user, string $password): void
    {
        if (! $this->canChangeOwnPassword($user)) {
            throw new DomainException(
                'Only local public accounts can change a password here. Directory and Cloudron accounts stay on Cloudron.',
            );
        }

        $this->applyOwnPassword(
            $user,
            $password,
            'auth.public_local_password_changed',
            'change_own_local_public_password',
        );
    }

    public function reset(User $actor, User $target, ?string $reason = null): string
    {
        $refusal = $this->refusalMessage($actor, $target);

        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        $temporaryPassword = Str::password(20);

        DB::transaction(function () use ($actor, $target, $temporaryPassword, $reason): void {
            $target->forceFill([
                'password' => $temporaryPassword,
                'authorization_epoch' => (int) $target->authorization_epoch + 1,
            ]);
            $target->setRememberToken(Str::random(60));
            $target->save();

            $this->auditLogger->record(
                'auth.local_public_password_reset',
                $actor,
                $target,
                array_filter([
                    'target_user_id' => $target->id,
                    'action' => 'reset_local_public_password',
                    'reason_length' => is_string($reason) && $reason !== '' ? mb_strlen($reason) : null,
                ], static fn (mixed $value): bool => $value !== null),
            );
        });

        $target->notify(new PasswordChangedNotification);

        return $temporaryPassword;
    }

    public function refusalMessage(User $actor, User $target): ?string
    {
        if (! $this->mutations->canManageTarget($actor, $target)
            && ! $this->mutations->canManagePublicClient($actor, $target)) {
            return 'You cannot reset the password for this account.';
        }

        if ($target->isPendingFirstLogin()) {
            return 'Pending first-login directory accounts stay on Cloudron.';
        }

        if (
            ! $target->isLocalPasswordIdentity()
            || $this->profile->hasDirectoryProtectedEligibility($target)
        ) {
            return 'Only local public accounts can have their password reset here. Directory and Cloudron accounts stay on Cloudron.';
        }

        return null;
    }

    private function isLocalPublicPasswordAccount(?User $user): bool
    {
        if ($user === null || $user->suspended_at !== null || $user->isProfileOnly()) {
            return false;
        }

        if ($user->isPendingFirstLogin()) {
            return false;
        }

        return $user->isLocalPasswordIdentity()
            && ! $this->profile->hasDirectoryProtectedEligibility($user);
    }

    private function applyOwnPassword(
        User $user,
        string $password,
        string $event,
        string $action,
    ): void {
        $wasTemporary = (bool) $user->must_change_password;

        DB::transaction(function () use ($user, $password, $event, $action, $wasTemporary): void {
            $user->forceFill([
                'password' => $password,
                'authorization_epoch' => (int) $user->authorization_epoch + 1,
                'account_state' => User::ACCOUNT_STATE_ACTIVE,
                'must_change_password' => false,
                'temporary_password_expires_at' => null,
            ]);
            $user->setRememberToken(Str::random(60));
            $user->save();

            $this->auditLogger->record(
                $event,
                $user,
                $user,
                [
                    'target_user_id' => $user->id,
                    'action' => $action,
                ],
            );

            if ($wasTemporary) {
                $this->auditLogger->record(
                    'temporary_password.used',
                    $user,
                    $user,
                    [
                        'target_user_id' => $user->id,
                    ],
                );
            }
        });

        $user->notify(new PasswordChangedNotification);
    }
}
