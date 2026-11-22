<?php

namespace App\Core\Push;

use App\Core\Accounts\User;

/**
 * Seeds role defaults on first device subscription (#795 hooks here later).
 */
final class PushPreferenceInitializer
{
    public function __construct(
        private readonly PushNotificationTypeRegistry $types,
        private readonly PushRoleResolver $roles,
    ) {}

    public function seedDefaultsForUser(User $user): void
    {
        if ($user->pushPreferences()->exists()) {
            return;
        }

        $pushRole = $this->roles->forUser($user);
        if ($pushRole === null) {
            return;
        }

        $rows = [];
        foreach ($this->types->all() as $type) {
            $default = $type->defaultForRole($pushRole);
            if (! $default->availableToRole()) {
                continue;
            }

            $rows[] = [
                'user_id' => $user->id,
                'type' => $type->key,
                'enabled' => $default->enabledByDefault(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            UserPushPreference::query()->insert($rows);
        }
    }
}