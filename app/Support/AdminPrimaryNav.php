<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolves the Admin primary-nav destination from the first openable child page.
 *
 * Bare shell tokens (`admin.access` / `superadmin.access`) do not paint a door.
 */
final class AdminPrimaryNav
{
    public function __construct(
        private readonly Gate $gate,
    ) {}

    /**
     * First Admin child the user can open, or null when none are allowed.
     *
     * @return array{url: string, active: bool}|null
     */
    public function forUser(?Authenticatable $user): ?array
    {
        if ($user === null) {
            return null;
        }

        foreach ($this->destinations() as $destination) {
            if (! $this->allowsAny($user, $destination['abilities'])) {
                continue;
            }

            return [
                'url' => $destination['url'],
                'active' => request()->routeIs('admin.*', 'superadmin.*'),
            ];
        }

        return null;
    }

    /**
     * @return list<array{abilities: list<string>, url: string}>
     */
    private function destinations(): array
    {
        return [
            [
                'abilities' => ['admin.analytics.view'],
                'url' => route('admin.index'),
            ],
            [
                'abilities' => ['admin.users.view'],
                'url' => route('admin.users.index', ['account_type' => 'public']),
            ],
            [
                'abilities' => ['admin.users.view'],
                'url' => route('admin.users.index', ['account_type' => 'staff']),
            ],
            [
                'abilities' => ['admin.groups.view', 'admin.roles.view', 'admin.permissions.view'],
                'url' => route('admin.access.index'),
            ],
            [
                'abilities' => ['admin.modules.view'],
                'url' => route('admin.organisation-modules.index'),
            ],
            [
                'abilities' => ['admin.modules.view'],
                'url' => route('admin.modules.index'),
            ],
            [
                'abilities' => ['admin.emails.view'],
                'url' => route('admin.emails.index'),
            ],
            [
                'abilities' => ['admin.maintenance.manage'],
                'url' => route('admin.maintenance.index'),
            ],
        ];
    }

    /**
     * @param  list<string>  $abilities
     */
    private function allowsAny(Authenticatable $user, array $abilities): bool
    {
        foreach ($abilities as $ability) {
            if ($this->gate->forUser($user)->allows($ability)) {
                return true;
            }
        }

        return false;
    }
}
