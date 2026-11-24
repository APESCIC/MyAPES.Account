<?php

namespace App\Services;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Last security-related audit events for the signed-in account (#920).
 */
final class AccountSecurityActivity
{
    private const LIMIT = 20;

    /** @var list<string> */
    private const EVENT_PREFIXES = [
        'auth.',
        'profile.phone_pin_',
        'profile.username_updated',
        'profile.email_change_',
    ];

    /** @var list<string> */
    private const SECRET_CONTEXT_KEYS = [
        'password',
        'temporary_password',
        'pin',
        'plain_pin',
        'secret',
        'token',
        'recovery_codes',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'otp',
        'code',
    ];

    /** @var list<string> */
    private const CONTEXT_ALLOW_LIST = [
        'action',
        'channel',
        'target_user_id',
        'passkey_id',
        'passkey_name',
    ];

    /**
     * @return list<array{event: string, created_at: ?Carbon, ip_address: ?string, context: array<string, mixed>}>
     */
    public function recentFor(User $user): array
    {
        return $this->query($user)
            ->map(function (AuditLog $audit): array {
                $context = is_array($audit->context) ? $audit->context : [];

                return [
                    'event' => (string) $audit->event,
                    'created_at' => $audit->created_at,
                    'ip_address' => $audit->ip_address,
                    'context' => $this->sanitiseContext($context),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, AuditLog>
     */
    private function query(User $user): Collection
    {
        return AuditLog::query()
            ->where(function ($query) use ($user): void {
                $query->where('user_id', $user->id)
                    ->orWhere(function ($nested) use ($user): void {
                        $nested->where('auditable_type', $user->getMorphClass())
                            ->where('auditable_id', $user->id);
                    });
            })
            ->where(function ($query): void {
                foreach (self::EVENT_PREFIXES as $prefix) {
                    $query->orWhere('event', 'like', $prefix.'%');
                }
            })
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitiseContext(array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            $key = (string) $key;
            $lower = strtolower($key);

            if (in_array($lower, self::SECRET_CONTEXT_KEYS, true)) {
                continue;
            }

            foreach (['password', 'secret', 'token', 'pin', 'recovery'] as $fragment) {
                if (str_contains($lower, $fragment)) {
                    continue 2;
                }
            }

            if (! in_array($key, self::CONTEXT_ALLOW_LIST, true)) {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return Arr::only($safe, self::CONTEXT_ALLOW_LIST);
    }
}