<?php

namespace App\Services;

use App\Core\Accounts\Permission;
use App\Core\Accounts\User;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Spatie\Permission\WildcardPermission;

class AuthorizationDirectPermissionPolicy
{
    public function __construct(
        private readonly AuthorizationProfile $profile,
    ) {}

    /**
     * @return array<int, string>
     */
    public function targets(Permission $permission): array
    {
        if (! $permission->exists || $permission->guard_name !== 'web') {
            return [];
        }

        $probe = new User;
        $probe->setRelation(
            'permissions',
            new Collection([$permission]),
        );
        $probe->setRelation('roles', new Collection);
        $wildcard = new WildcardPermission($probe);
        $index = $wildcard->getIndex();

        return collect($this->profile->permissions())
            ->filter(
                static fn (string $ability): bool => $wildcard->implies(
                    $ability,
                    'web',
                    $index,
                ),
            )
            ->values()
            ->all();
    }

    public function assertAssignable(Permission $permission): void
    {
        $targets = $this->targets($permission);

        if ($targets === []) {
            throw new InvalidArgumentException(
                'Direct permissions must resolve within the application catalogue.',
            );
        }

        if (collect($targets)->contains(
            fn (string $ability): bool => $this->profile
                ->isAdministratorOnlyPermission($ability),
        )) {
            throw new InvalidArgumentException(
                'Administrator-only permissions cannot be assigned directly.',
            );
        }
    }
}
