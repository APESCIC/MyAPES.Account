<?php

namespace App\Core\Push;

/**
 * Extracts a division (sub-core) key from a Spatie permission name.
 */
final class PushDivisionKey
{
    public static function fromPermission(?string $permission): ?string
    {
        if ($permission === null || $permission === '') {
            return null;
        }

        $parts = explode('.', $permission, 2);

        return $parts[0] !== '' ? $parts[0] : null;
    }
}
