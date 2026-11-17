<?php

namespace App\Support;

use App\Contracts\ModuleRegistry;

final class JobRoleCapabilityPacks
{
    /**
     * @return array<string, array{title: string, permissions: array<int, string>}>
     */
    public static function definitions(ModuleRegistry $modules): array
    {
        $staffWork = [];
        $moduleDelete = [];

        foreach ($modules->permissions() as $permission) {
            if (! $permission->requiresDirectoryContext) {
                continue;
            }

            if ($permission->ability === 'delete') {
                $moduleDelete[] = $permission->name;
            } else {
                $staffWork[] = $permission->name;
            }
        }

        sort($staffWork);
        sort($moduleDelete);

        return [
            'admin-overview' => [
                'title' => 'Admin overview',
                'permissions' => ['admin.analytics.view'],
            ],
            'view-accounts' => [
                'title' => 'View accounts',
                'permissions' => ['admin.users.view', 'client.search'],
            ],
            'manage-accounts' => [
                'title' => 'Manage accounts',
                'permissions' => ['admin.users.manage', 'client.manage'],
            ],
            'staff-module-work' => [
                'title' => 'Staff module work',
                'permissions' => $staffWork,
            ],
            'module-delete' => [
                'title' => 'Module delete',
                'permissions' => $moduleDelete,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $packKeys
     * @return array<int, string>
     */
    public static function expand(array $packKeys, ModuleRegistry $modules): array
    {
        $definitions = self::definitions($modules);
        $permissions = [];

        foreach ($packKeys as $key) {
            foreach ($definitions[$key]['permissions'] ?? [] as $permission) {
                $permissions[] = $permission;
            }
        }

        $permissions = array_values(array_unique($permissions));
        sort($permissions);

        return $permissions;
    }

    /**
     * @param  array<int, string>  $selectedPermissions
     * @return 'on'|'off'|'indeterminate'
     */
    public static function state(
        string $packKey,
        array $selectedPermissions,
        ModuleRegistry $modules,
    ): string {
        $pack = self::definitions($modules)[$packKey]['permissions'] ?? [];

        if ($pack === []) {
            return 'off';
        }

        $selected = array_fill_keys($selectedPermissions, true);
        $hits = 0;

        foreach ($pack as $permission) {
            if (isset($selected[$permission])) {
                $hits++;
            }
        }

        if ($hits === 0) {
            return 'off';
        }

        if ($hits === count($pack)) {
            return 'on';
        }

        return 'indeterminate';
    }

    /**
     * @param  array<int, string>  $selectedPermissions
     * @param  array<int, string>  $packKeysOn
     * @param  array<int, string>  $packKeysOff
     * @return array<int, string>
     */
    public static function merge(
        array $selectedPermissions,
        array $packKeysOn,
        array $packKeysOff,
        ModuleRegistry $modules,
    ): array {
        $set = array_fill_keys($selectedPermissions, true);

        foreach (self::expand($packKeysOff, $modules) as $permission) {
            unset($set[$permission]);
        }

        foreach (self::expand($packKeysOn, $modules) as $permission) {
            $set[$permission] = true;
        }

        $permissions = array_keys($set);
        sort($permissions);

        return $permissions;
    }
}
