<?php

namespace App\Services;

use App\Core\Accounts\DirectoryGroup;
use App\Core\Accounts\DirectoryGroupRoleMapping;
use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Exceptions\AuthorizationLifecycleException;
use App\Support\DefaultJobRoles;
use App\Support\DirectoryImmutableMappings;
use App\Support\DirectoryLegacyGroupAliases;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

class AuthorizationMetadataSynchronizer
{
    public function __construct(
        private readonly AuthorizationProfile $profile,
        private readonly AuthorizationPermissionSynchronizer $permissions,
        private readonly AuthorizationSuperAdminRetirement $superAdminRetirement,
    ) {}

    public function synchronize(): void
    {
        $this->superAdminRetirement->retire();
        $this->synchronizeRoles();
        $this->synchronizePermissions();
        $this->synchronizeDefaultJobRoles();
        $this->synchronizeMappings();
    }

    private function synchronizeRoles(): void
    {
        foreach ($this->profile->protectedRolesByPrecedence() as $name) {
            $role = Role::query()
                ->where('guard_name', 'web')
                ->where('name', $name)
                ->first();

            if ($role === null) {
                $role = new Role;
                $role->forceFill([
                    'name' => $name,
                    'guard_name' => 'web',
                    'is_protected' => true,
                ])->save();
            }

            if (! $role->is_protected) {
                throw new AuthorizationLifecycleException('protected_roles');
            }
        }

        if (Role::query()
            ->where('guard_name', 'web')
            ->where('is_protected', true)
            ->whereNotIn('name', $this->profile->protectedRolesByPrecedence())
            ->exists()) {
            throw new AuthorizationLifecycleException('protected_roles');
        }
    }

    private function synchronizePermissions(): void
    {
        $expected = $this->profile->permissions();

        foreach ($expected as $name) {
            $permission = Permission::query()
                ->where('guard_name', 'web')
                ->where('name', $name)
                ->first();

            if ($permission === null) {
                $permission = new Permission;
                $permission->forceFill([
                    'name' => $name,
                    'guard_name' => 'web',
                    'is_code_owned' => true,
                ])->save();
            } elseif (! $permission->is_code_owned) {
                DB::table('permissions')
                    ->where('id', $permission->getKey())
                    ->update([
                        'is_code_owned' => true,
                        'updated_at' => now(),
                    ]);
            }
        }

        DB::table('permissions')
            ->where('guard_name', 'web')
            ->where('is_code_owned', true)
            ->whereNotIn('name', $expected)
            ->update([
                'is_code_owned' => false,
                'updated_at' => now(),
            ]);
        $this->permissions->synchronize();
    }

    private function synchronizeDefaultJobRoles(): void
    {
        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', $this->profile->permissions())
            ->pluck('id', 'name');

        foreach (DefaultJobRoles::names() as $name) {
            $role = Role::query()
                ->where('guard_name', 'web')
                ->where('name', $name)
                ->first();

            if ($role === null) {
                $role = new Role;
                $role->forceFill([
                    'name' => $name,
                    'guard_name' => 'web',
                    'is_protected' => false,
                ])->save();

                $rows = [];

                foreach (DefaultJobRoles::defaultPermissions($name) as $permission) {
                    if ($this->profile->isAdministratorOnlyPermission($permission)
                        || ! $permissionIds->has($permission)) {
                        continue;
                    }

                    $rows[] = [
                        'role_id' => $role->getKey(),
                        'permission_id' => $permissionIds->get($permission),
                    ];
                }

                if ($rows !== []) {
                    DB::table('role_has_permissions')->insert($rows);
                }

                $this->seedJobRoleMapping($role);

                continue;
            }

            if ($role->is_protected) {
                throw new AuthorizationLifecycleException('protected_roles');
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Seeded once, with the role, so an administrator may later remove or change it (#534).
     */
    private function seedJobRoleMapping(Role $role): void
    {
        $groupName = DefaultJobRoles::directoryGroup($role->name);

        if ($groupName === null) {
            return;
        }

        $group = $this->findOrCreateDirectoryGroup($groupName);

        $exists = DirectoryGroupRoleMapping::query()
            ->whereBelongsTo($group)
            ->whereBelongsTo($role)
            ->exists();

        if (! $exists) {
            (new DirectoryGroupRoleMapping)->forceFill([
                'directory_group_id' => $group->getKey(),
                'role_id' => $role->getKey(),
                'is_immutable' => false,
            ])->save();
        }
    }

    private function findOrCreateDirectoryGroup(string $groupName): DirectoryGroup
    {
        $group = DirectoryGroup::query()
            ->where('name', $groupName)
            ->first();

        if ($group === null) {
            $attributes = [
                'name' => $groupName,
                'external_id' => null,
                'member_count' => null,
                'status' => DirectoryGroup::STATUS_MISSING,
                'first_seen_at' => null,
                'last_seen_at' => null,
                'last_synced_at' => null,
            ];

            if (Schema::hasColumn('directory_groups', 'app_enabled')) {
                $attributes['app_enabled'] = true;
            }

            return DirectoryGroup::query()->create($attributes);
        }

        if (Schema::hasColumn('directory_groups', 'app_enabled')
            && ! $group->app_enabled) {
            $group->forceFill([
                'app_enabled' => true,
            ])->save();
        }

        return $group;
    }

    private function synchronizeMappings(): void
    {
        $expectedMappingIds = [];

        foreach (DirectoryImmutableMappings::all() as $groupName => $roleName) {
            $group = $this->findOrCreateDirectoryGroup($groupName);

            $role = Role::query()
                ->where('guard_name', 'web')
                ->where('name', $roleName)
                ->firstOrFail();
            $mapping = DirectoryGroupRoleMapping::query()
                ->whereBelongsTo($group)
                ->whereBelongsTo($role)
                ->first();

            if ($mapping === null) {
                $mapping = new DirectoryGroupRoleMapping;
                $mapping->forceFill([
                    'directory_group_id' => $group->getKey(),
                    'role_id' => $role->getKey(),
                    'is_immutable' => true,
                ])->save();
            } elseif (! $mapping->is_immutable) {
                $mapping->forceFill([
                    'is_immutable' => true,
                ])->save();
            }

            $expectedMappingIds[] = $mapping->getKey();
        }

        DirectoryGroupRoleMapping::query()
            ->where('is_immutable', true)
            ->whereNotIn('id', $expectedMappingIds)
            ->update([
                'is_immutable' => false,
                'updated_at' => now(),
            ]);

        $mappings = DB::table('directory_group_role_mappings')
            ->join(
                'directory_groups',
                'directory_groups.id',
                '=',
                'directory_group_role_mappings.directory_group_id',
            )
            ->join(
                'roles',
                'roles.id',
                '=',
                'directory_group_role_mappings.role_id',
            )
            ->get([
                'directory_groups.name as group_name',
                'roles.guard_name',
            ]);

        foreach ($mappings as $mapping) {
            if (! is_string($mapping->group_name)
                || $mapping->group_name === ''
                || $mapping->group_name
                    !== strtolower(trim($mapping->group_name))
                || preg_match('/[*?%]/', $mapping->group_name) === 1
                || in_array(
                    $mapping->group_name,
                    DirectoryLegacyGroupAliases::all(),
                    true,
                )
                || $mapping->guard_name !== 'web') {
                throw new AuthorizationLifecycleException(
                    'mapping_integrity',
                );
            }
        }
    }
}
