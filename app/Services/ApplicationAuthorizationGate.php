<?php

namespace App\Services;

use App\Contracts\ModuleRegistry;
use App\Core\Access\PermissionNaming;
use App\Core\Accounts\Permission;
use App\Core\Accounts\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class ApplicationAuthorizationGate
{
    public function __construct(
        private readonly AuthorizationProfile $profile,
        private readonly AuthorizationDirectPermissionPolicy $permissionPolicy,
        private readonly SessionAuthorizationContext $context,
        private readonly Request $request,
        private readonly ModuleRegistry $modules,
        private readonly ServiceEntitlement $entitlement,
    ) {}

    public function authorize(User $user, string $ability): ?bool
    {
        if ($user->suspended_at !== null) {
            return false;
        }

        $ability = PermissionNaming::resolve($ability);

        if (! $this->profile->isApplicationPermission($ability)) {
            return null;
        }

        $modulePermission = $this->modules->permission($ability);
        if ($modulePermission !== null
            && ! $modulePermission->requiresDirectoryContext
            && ! $this->entitlement->allows(
                $user,
                $modulePermission->subCoreKey,
                $this->request,
            )) {
            return false;
        }

        $directoryRestricted = $this->profile
            ->isDirectoryRestrictedPermission($ability);

        if ($directoryRestricted) {
            if (! $this->context->permitsDirectoryRestricted(
                $this->request,
                $user,
            )) {
                return false;
            }

            $eligible = User::query();

            if ($this->profile->isAdministratorOnlyPermission($ability)) {
                $eligible->eligibleAdministrators();
            } else {
                $eligible->eligibleStaff();
            }

            if (! $eligible
                ->whereKey($user->getKey())
                ->exists()) {
                return false;
            }

            if ($this->profile->isAdministratorOnlyPermission($ability)) {
                return $this->profile->isAdministrator($user);
            }

            if ($this->profile->isAdministrator($user)) {
                return true;
            }
        }

        return $this->hasApplicationPermission($user, $ability);
    }

    private function hasApplicationPermission(User $user, string $ability): bool
    {
        $user->loadMissing(['permissions', 'roles.permissions']);

        /** @var Collection<int, Permission> $permissions */
        $permissions = $user->permissions
            ->merge(
                $user->roles->flatMap(
                    static fn ($role): Collection => $role->permissions,
                ),
            );

        return $permissions->contains(
            fn ($permission): bool => $permission->guard_name === 'web'
                && (
                    $permission->name === $ability
                    || (str_contains($permission->name, '*')
                        && in_array(
                            $ability,
                            $this->permissionPolicy->targets($permission),
                            true,
                        ))
                ),
        );
    }
}
