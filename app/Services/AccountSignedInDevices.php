<?php

namespace App\Services;

use App\Core\Accounts\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
/**
 * Lists persisted web sessions for the account security page (#920).
 */
final class AccountSignedInDevices
{
    /**
     * @return list<array{
     *     id: string,
     *     ip_address: ?string,
     *     user_agent: ?string,
     *     last_activity: ?Carbon,
     *     current: bool,
     *     label: string,
     * }>
     */
    public function forUser(User $user, Request $request): array
    {
        $currentId = $request->session()->getId();
        $driver = (string) config('session.driver');

        if ($driver !== 'database') {
            return [
                $this->currentDevice($currentId, $request, true),
            ];
        }

        $table = (string) config('session.table', 'sessions');
        if (! Schema::hasTable($table)) {
            return [
                $this->currentDevice($currentId, $request, true),
            ];
        }

        $rows = DB::table($table)
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get();

        if ($rows->isEmpty()) {
            return [
                $this->currentDevice($currentId, $request, true),
            ];
        }

        return $rows
            ->map(function (object $row) use ($currentId): array {
                $lastActivity = is_numeric($row->last_activity ?? null)
                    ? Carbon::createFromTimestamp((int) $row->last_activity)
                    : null;

                return [
                    'id' => (string) $row->id,
                    'ip_address' => is_string($row->ip_address ?? null) ? $row->ip_address : null,
                    'user_agent' => is_string($row->user_agent ?? null) ? $row->user_agent : null,
                    'last_activity' => $lastActivity,
                    'current' => (string) $row->id === $currentId,
                    'label' => $this->labelFromUserAgent(is_string($row->user_agent ?? null) ? $row->user_agent : null),
                ];
            })
            ->values()
            ->all();
    }

    public function canListMultipleDevices(): bool
    {
        return (string) config('session.driver') === 'database'
            && Schema::hasTable((string) config('session.table', 'sessions'));
    }

    /**
     * @return array{
     *     id: string,
     *     ip_address: ?string,
     *     user_agent: ?string,
     *     last_activity: ?Carbon,
     *     current: bool,
     *     label: string,
     * }
     */
    private function currentDevice(string $sessionId, Request $request, bool $current): array
    {
        $userAgent = $request->userAgent();

        return [
            'id' => $sessionId,
            'ip_address' => $request->ip(),
            'user_agent' => is_string($userAgent) ? $userAgent : null,
            'last_activity' => now(),
            'current' => $current,
            'label' => $this->labelFromUserAgent(is_string($userAgent) ? $userAgent : null),
        ];
    }

    private function labelFromUserAgent(?string $userAgent): string
    {
        if ($userAgent === null || $userAgent === '') {
            return __('account.settings.security_devices.unknown_device');
        }

        $browser = 'Browser';
        if (preg_match('/Edg\//', $userAgent) === 1) {
            $browser = 'Microsoft Edge';
        } elseif (preg_match('/Chrome\//', $userAgent) === 1) {
            $browser = 'Chrome';
        } elseif (preg_match('/Firefox\//', $userAgent) === 1) {
            $browser = 'Firefox';
        } elseif (preg_match('/Safari\//', $userAgent) === 1 && ! str_contains($userAgent, 'Chrome')) {
            $browser = 'Safari';
        }

        $platform = 'device';
        if (preg_match('/Windows/i', $userAgent) === 1) {
            $platform = 'Windows';
        } elseif (preg_match('/Macintosh|Mac OS X/i', $userAgent) === 1) {
            $platform = 'macOS';
        } elseif (preg_match('/Android/i', $userAgent) === 1) {
            $platform = 'Android';
        } elseif (preg_match('/iPhone|iPad/i', $userAgent) === 1) {
            $platform = 'iOS';
        } elseif (preg_match('/Linux/i', $userAgent) === 1) {
            $platform = 'Linux';
        }

        return __('account.settings.security_devices.label', [
            'browser' => $browser,
            'platform' => $platform,
        ]);
    }
}