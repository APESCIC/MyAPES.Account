<?php

namespace App\Core\Push;

/**
 * Per-role default when push is first enabled on a device (#791).
 */
enum PushRoleDefault: string
{
    case On = 'on';

    case OptIn = 'opt_in';

    /** Type is not offered to this role (—). */
    case NotApplicable = 'na';

    /** Type must never be enabled for this role. */
    case Never = 'never';

    public function enabledByDefault(): bool
    {
        return $this === self::On;
    }

    public function availableToRole(): bool
    {
        return match ($this) {
            self::On, self::OptIn => true,
            self::NotApplicable, self::Never => false,
        };
    }
}