<?php

namespace App\Core\Push;

use Illuminate\Support\Str;

/**
 * Validates push subscription endpoints against HTTPS and an allow-list (#792).
 */
final class PushEndpointValidator
{
    public static function accepts(string $endpoint): bool
    {
        if (! Str::startsWith($endpoint, 'https://')) {
            return false;
        }

        $host = parse_url($endpoint, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $exact = [
            'fcm.googleapis.com',
            'updates.push.services.mozilla.com',
            'web.push.apple.com',
        ];

        if (in_array($host, $exact, true)) {
            return true;
        }

        if ($host === 'notify.windows.com' || str_ends_with($host, '.notify.windows.com')) {
            return true;
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($appHost) && $appHost !== '' && $host === $appHost;
    }
}
