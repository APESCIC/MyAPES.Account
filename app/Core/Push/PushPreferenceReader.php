<?php

namespace App\Core\Push;

use App\Core\Accounts\User;

final class PushPreferenceReader
{
    public function __construct(
        private readonly PushNotificationTypeRegistry $types,
        private readonly PushRoleResolver $roles,
    ) {}

    public function isTypeEnabled(User $user, string $typeKey): bool
    {
        $type = $this->types->get($typeKey);
        $pushRole = $this->roles->forUser($user);

        if (! $type->availableForRole($pushRole)) {
            return false;
        }

        $stored = $user->pushPreferences()
            ->where('type', $typeKey)
            ->value('enabled');

        if ($stored === null) {
            return $type->defaultForRole($pushRole)->enabledByDefault();
        }

        return (bool) $stored;
    }

    public function isEmailEnabled(User $user, string $typeKey): bool
    {
        $type = $this->types->get($typeKey);
        if ($type->securityLocked) {
            return true;
        }

        $pushRole = $this->roles->forUser($user);

        if (! $type->availableForRole($pushRole)) {
            return false;
        }

        $stored = $user->pushPreferences()
            ->where('type', $typeKey)
            ->value('email_enabled');

        if ($stored === null) {
            return $type->defaultForRole($pushRole)->enabledByDefault();
        }

        return (bool) $stored;
    }
}
