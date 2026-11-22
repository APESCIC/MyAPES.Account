<?php

namespace App\Core\Push;

/**
 * Short fingerprint for a VAPID public key shown in Admin (#803).
 */
final class VapidPublicKeyFingerprint
{
    public static function format(?string $publicKey): ?string
    {
        if ($publicKey === null || $publicKey === '') {
            return null;
        }

        $hash = hash('sha256', $publicKey);

        return strtoupper(substr($hash, 0, 8).'…'.substr($hash, -8));
    }
}