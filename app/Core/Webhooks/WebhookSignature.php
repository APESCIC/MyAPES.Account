<?php

namespace App\Core\Webhooks;

/**
 * HMAC-SHA256 signatures for outgoing webhooks (#641), and the sample check a
 * receiving app should copy. The header is "t=<unix time>,v1=<hex HMAC of
 * '<unix time>.<raw body>'>"; a receiver rejects a bad signature or a timestamp
 * more than five minutes away, so a captured request cannot be replayed later.
 */
final class WebhookSignature
{
    public const HEADER = 'X-MyAPES-Signature';

    public const EVENT_HEADER = 'X-MyAPES-Event';

    public const DELIVERY_HEADER = 'X-MyAPES-Event-Id';

    public const TOLERANCE_SECONDS = 300;

    public static function sign(string $body, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public static function verify(string $body, string $header, string $secret, ?int $now = null, int $tolerance = self::TOLERANCE_SECONDS): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];
        if (abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), $parts['v1']);
    }
}
