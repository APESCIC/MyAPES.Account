<?php

namespace App\Services;

use App\Core\Accounts\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Issues single-use temporary passwords for staff-created MyAPES accounts (#939).
 * Plaintext is returned once to the caller for email delivery and never stored.
 */
final class TemporaryPasswordService
{
    public const MIN_LENGTH = 14;

    public const TTL_HOURS = 72;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Generate a firstname.lastname username, appending a number when taken.
     */
    public function generateUsername(string $firstName, string $lastName): string
    {
        $first = $this->slugPart($firstName) ?: 'user';
        $last = $this->slugPart($lastName) ?: 'client';
        $base = substr($first.'.'.$last, 0, 28);

        if (! User::query()->where('username', $base)->exists()) {
            return $base;
        }

        for ($i = 2; $i < 1000; $i++) {
            $suffix = (string) $i;
            $candidate = substr($base, 0, 30 - strlen($suffix)).$suffix;
            if (! User::query()->where('username', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'user.'.Str::lower(Str::random(8));
    }

    /**
     * Hash a new temporary password onto the user, set invited + force-change + 72h expiry.
     *
     * @return non-empty-string plaintext password (caller must deliver out of band / email once)
     */
    public function issue(User $user, ?User $actor, string $event = 'temporary_password.issued'): string
    {
        $plain = Str::password(self::MIN_LENGTH);

        DB::transaction(function () use ($user, $actor, $event, $plain): void {
            $user->forceFill([
                'password' => $plain,
                'must_change_password' => true,
                'temporary_password_expires_at' => now()->addHours(self::TTL_HOURS),
                'account_state' => User::ACCOUNT_STATE_INVITED,
                'authorization_epoch' => (int) $user->authorization_epoch + 1,
            ]);
            $user->setRememberToken(Str::random(60));
            $user->save();

            $this->audit->record($event, $actor, $user, [
                'target_user_id' => $user->id,
                'expires_at' => $user->temporary_password_expires_at?->toIso8601String(),
                'ttl_hours' => self::TTL_HOURS,
            ]);
        });

        return $plain;
    }

    public function isTemporaryPasswordActive(User $user): bool
    {
        return (bool) $user->must_change_password
            && $user->temporary_password_expires_at !== null
            && $user->temporary_password_expires_at->isFuture();
    }

    public function isTemporaryPasswordExpired(User $user): bool
    {
        return (bool) $user->must_change_password
            && $user->temporary_password_expires_at !== null
            && $user->temporary_password_expires_at->isPast();
    }

    public function matchesCurrentPassword(User $user, #[\SensitiveParameter] string $plain): bool
    {
        return is_string($user->password) && Hash::check($plain, $user->password);
    }

    /**
     * Clear force-change after a successful permanent password is set.
     */
    public function markUsed(User $user, string $event = 'temporary_password.used'): void
    {
        DB::transaction(function () use ($user, $event): void {
            $user->forceFill([
                'must_change_password' => false,
                'temporary_password_expires_at' => null,
                'account_state' => User::ACCOUNT_STATE_ACTIVE,
            ])->save();

            $this->audit->record($event, $user, $user, [
                'target_user_id' => $user->id,
            ]);
        });
    }

    public function recordExpiredAttempt(User $user): void
    {
        $this->audit->record('temporary_password.expired', $user, $user, [
            'target_user_id' => $user->id,
        ]);
    }

    private function slugPart(string $value): string
    {
        $ascii = Str::ascii(Str::lower(trim($value)));
        $clean = preg_replace('/[^a-z0-9]/', '', $ascii) ?? '';

        return $clean;
    }
}
