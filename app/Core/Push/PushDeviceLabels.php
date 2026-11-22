<?php

namespace App\Core\Push;

/**
 * Human-readable browser and system labels from a user agent string.
 */
final class PushDeviceLabels
{
    /**
     * @return array{browser: string, system: string}
     */
    public function summarise(?string $userAgent): array
    {
        $userAgent = trim((string) $userAgent);
        if ($userAgent === '') {
            return ['browser' => __('push.profile.device_unknown_browser'), 'system' => __('push.profile.device_unknown_system')];
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Microsoft Edge',
            str_contains($userAgent, 'Chrome/') && ! str_contains($userAgent, 'Edg/') => 'Chrome',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') && ! str_contains($userAgent, 'Chrome/') => 'Safari',
            default => __('push.profile.device_unknown_browser'),
        };

        $system = match (true) {
            str_contains($userAgent, 'Windows NT') => 'Windows',
            str_contains($userAgent, 'Mac OS X') && ! str_contains($userAgent, 'iPhone') && ! str_contains($userAgent, 'iPad') => 'macOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => __('push.profile.device_unknown_system'),
        };

        return ['browser' => $browser, 'system' => $system];
    }
}
