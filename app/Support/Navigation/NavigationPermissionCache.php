<?php

namespace App\Support\Navigation;

use App\Core\Accounts\User;

/**
 * Memoises {@see User::can()} for one request while navigation is built (#893).
 */
final class NavigationPermissionCache
{
    /** @var array<string, bool> */
    private array $cache = [];

    public function can(User $user, string $ability): bool
    {
        $key = $user->getKey().':'.$ability;
        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $user->can($ability);
        }

        return $this->cache[$key];
    }
}