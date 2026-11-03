<?php

namespace App\Core\Access;

use App\Contracts\ModuleRegistry;
use App\Core\Accounts\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-set plugin access for staff, volunteers and students (#460).
 *
 * A switch can only take away a group's default plugin permissions, never add
 * to them, and administrators are never affected.
 */
final class GroupAccessMatrix
{
    public const GROUPS = [
        AuthorizationProfile::ROLE_STAFF,
        AuthorizationProfile::ROLE_VOLUNTEER,
        AuthorizationProfile::ROLE_STUDENT,
    ];

    /** @var array<string, array<string, true>>|null */
    private ?array $denied = null;

    /** @var array<int|string, string|null> */
    private array $roleByUser = [];

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly AuthorizationProfile $profile,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function denies(User $user, string $subCoreKey, string $moduleKey): bool
    {
        $denied = $this->deniedCells();

        if ($denied === []) {
            return false;
        }

        $role = $this->roleByUser[$user->getKey()]
            ??= $this->profile->effectiveProtectedRole($user);

        return $role !== null
            && isset($denied[$role][self::cellKey($subCoreKey, $moduleKey)]);
    }

    public function allows(string $role, string $subCoreKey, string $moduleKey): bool
    {
        return ! isset($this->deniedCells()[$role][self::cellKey($subCoreKey, $moduleKey)]);
    }

    /**
     * One row per shipped plugin in each organisation module. A group's cell is
     * null when the group has no access to that plugin by default.
     *
     * @return list<array{key: string, sub_core_key: string, sub_core_name: string, module_key: string, module_name: string, groups: array<string, bool|null>}>
     */
    public function rows(): array
    {
        $defaults = $this->defaultCells();
        $rows = [];

        foreach ($this->modules->shippedInstances() as $instance) {
            $key = self::cellKey($instance->subCore->key, $instance->module->key);
            $groups = [];

            foreach (self::GROUPS as $role) {
                $groups[$role] = isset($defaults[$role][$key])
                    ? $this->allows($role, $instance->subCore->key, $instance->module->key)
                    : null;
            }

            $rows[] = [
                'key' => $key,
                'sub_core_key' => $instance->subCore->key,
                'sub_core_name' => $instance->subCore->name,
                'module_key' => $instance->module->key,
                'module_name' => $instance->module->name,
                'groups' => $groups,
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => [$a['sub_core_name'], $a['module_name']]
                <=> [$b['sub_core_name'], $b['module_name']],
        );

        return $rows;
    }

    /**
     * Apply a full matrix submission. Cells listed in $allowed stay on; every
     * other cell a group has by default is switched off.
     *
     * @param  array<string, array<string, mixed>>  $allowed  role => [cell key => checked]
     * @return int Number of cells that changed.
     */
    public function update(User $actor, array $allowed): int
    {
        $changes = [];

        foreach ($this->defaultCells() as $role => $cells) {
            foreach (array_keys($cells) as $key) {
                [$subCoreKey, $moduleKey] = explode(':', $key, 2);
                $before = $this->allows($role, $subCoreKey, $moduleKey);
                $after = isset($allowed[$role][$key]);

                if ($before !== $after) {
                    $changes[] = [$role, $subCoreKey, $moduleKey, $before, $after];
                }
            }
        }

        DB::transaction(function () use ($actor, $changes): void {
            foreach ($changes as [$role, $subCoreKey, $moduleKey, $before, $after]) {
                GroupPluginAccess::query()->updateOrCreate(
                    [
                        'role_name' => $role,
                        'sub_core_key' => $subCoreKey,
                        'module_key' => $moduleKey,
                    ],
                    [
                        'allowed' => $after,
                        'updated_by' => $actor->id,
                    ],
                );

                $this->auditLogger->record(
                    'authorization.access_matrix_updated',
                    $actor,
                    null,
                    [
                        'actor_id' => $actor->id,
                        'group' => $role,
                        'sub_core_key' => $subCoreKey,
                        'module_key' => $moduleKey,
                        'before' => $before ? 'on' : 'off',
                        'after' => $after ? 'on' : 'off',
                    ],
                );
            }
        });

        $this->flush();

        return count($changes);
    }

    public function flush(): void
    {
        $this->denied = null;
        $this->roleByUser = [];
    }

    public static function cellKey(string $subCoreKey, string $moduleKey): string
    {
        return "{$subCoreKey}:{$moduleKey}";
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function defaultCells(): array
    {
        $matrix = $this->profile->permissionMatrix();
        $cells = [];

        foreach (self::GROUPS as $role) {
            $cells[$role] = [];

            foreach ($matrix[$role] ?? [] as $permission) {
                $descriptor = $this->modules->permission($permission);

                if ($descriptor !== null) {
                    $cells[$role][self::cellKey($descriptor->subCoreKey, $descriptor->moduleKey)] = true;
                }
            }
        }

        return $cells;
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function deniedCells(): array
    {
        if ($this->denied !== null) {
            return $this->denied;
        }

        if (! Schema::hasTable('group_plugin_access')) {
            return $this->denied = [];
        }

        $denied = [];

        GroupPluginAccess::query()
            ->where('allowed', false)
            ->get(['role_name', 'sub_core_key', 'module_key'])
            ->each(function (GroupPluginAccess $row) use (&$denied): void {
                $denied[$row->role_name][self::cellKey($row->sub_core_key, $row->module_key)] = true;
            });

        return $this->denied = $denied;
    }
}
