<?php

namespace App\Services;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use Illuminate\Support\Carbon;

/**
 * Counts and recent events for Admin → Security → Overview (#948).
 * Returns names and counts only — never emails, secrets or raw context.
 */
final class SecurityOverviewAggregator
{
    public const FAILED_SIGN_IN_DAYS = 7;

    public const RECENT_EVENTS_LIMIT = 12;

    /**
     * @return list<string>
     */
    public static function failedSignInEvents(): array
    {
        return [
            'auth.public_login_failed',
            'auth.local_staff_login_failed',
        ];
    }

    /**
     * @return list<string>
     */
    public static function securityEventPrefixes(): array
    {
        return [
            'auth.',
            'authorization.user_suspended',
            'authorization.user_reactivated',
            'temporary_password.',
            'admin.settings.secret_',
            'admin.security.',
        ];
    }

    /**
     * @return array{
     *     two_factor_enabled: int,
     *     passkey_enabled: int,
     *     suspended: int,
     *     failed_sign_ins: int,
     *     recent_events: list<array{at: string, event: string, actor: ?string, subject: ?string}>
     * }
     */
    public function overview(): array
    {
        $failedSince = Carbon::now()->subDays(self::FAILED_SIGN_IN_DAYS);

        return [
            'two_factor_enabled' => User::query()->whereNotNull('two_factor_confirmed_at')->count(),
            'passkey_enabled' => User::query()->whereHas('passkeys')->count(),
            'suspended' => User::query()->whereNotNull('suspended_at')->count(),
            'failed_sign_ins' => AuditLog::query()
                ->whereIn('event', self::failedSignInEvents())
                ->where('created_at', '>=', $failedSince)
                ->count(),
            'recent_events' => $this->recentEvents(),
        ];
    }

    /**
     * @return list<array{at: string, event: string, actor: ?string, subject: ?string}>
     */
    private function recentEvents(): array
    {
        $query = AuditLog::query()->with(['user:id,name', 'auditable'])->latest('id')->limit(80);
        $rows = [];

        foreach ($query->cursor() as $log) {
            if (! $this->isSecurityEvent((string) $log->event)) {
                continue;
            }

            $subject = null;
            if ($log->auditable instanceof User) {
                $subject = $log->auditable->name;
            }

            $rows[] = [
                'at' => $log->created_at?->timezone(config('app.timezone'))->format('j M Y H:i') ?? '',
                'event' => (string) $log->event,
                'actor' => $log->user?->name,
                'subject' => $subject,
            ];

            if (count($rows) >= self::RECENT_EVENTS_LIMIT) {
                break;
            }
        }

        return $rows;
    }

    private function isSecurityEvent(string $event): bool
    {
        foreach (self::securityEventPrefixes() as $prefix) {
            if (str_starts_with($event, $prefix) || $event === $prefix) {
                return true;
            }
        }

        return false;
    }
}
