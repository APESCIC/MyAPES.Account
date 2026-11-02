<?php

namespace App\Services;

use App\Support\AuthorizationCompatibilityDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Folds the retired super-admin role, its directory group and the
 * superadmin.access permission into administrator (#459). Idempotent.
 */
class AuthorizationSuperAdminRetirement
{
    public const RETIRED_ROLE = 'super-admin';

    public const RETIRED_PERMISSION = 'superadmin.access';

    public const RETIRED_LEGACY_ACCESS_LEVEL = 'superadmin';

    /** @var array<int, string> */
    public const RETIRED_GROUPS = [
        'myapesaccount.superadmin',
        'myapesaccount.superadmins',
    ];

    private const ADMIN_GROUP = 'myapesaccount.admin';

    public function __construct(
        private readonly AuthorizationCompatibilityDatabaseGuard $guard,
    ) {}

    public function isPending(): bool
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return false;
        }

        return DB::table('roles')
            ->where('guard_name', 'web')
            ->where('name', self::RETIRED_ROLE)
            ->exists()
            || DB::table('permissions')
                ->where('guard_name', 'web')
                ->where('name', self::RETIRED_PERMISSION)
                ->exists()
            || (Schema::hasTable('directory_groups') && DB::table('directory_groups')
                ->whereIn('name', self::RETIRED_GROUPS)
                ->exists())
            || (Schema::hasColumn('users', 'legacy_access_level') && DB::table('users')
                ->where('legacy_access_level', self::RETIRED_LEGACY_ACCESS_LEVEL)
                ->exists());
    }

    public function retire(): void
    {
        if (! $this->isPending()) {
            return;
        }

        $reinstallGuard = $this->guard->isInstalled()
            || $this->guard->isSuperAdminRevisionInstalled()
            || $this->guard->isLegacyInstalled();

        $this->guard->drop();

        $affectedUserIds = [];
        $administratorId = $this->administratorRoleId();
        $retiredRoleId = DB::table('roles')
            ->where('guard_name', 'web')
            ->where('name', self::RETIRED_ROLE)
            ->value('id');

        if ($retiredRoleId !== null) {
            $affectedUserIds = $this->moveRoleSources((int) $retiredRoleId, $administratorId);
            $this->moveModelRoles((int) $retiredRoleId, $administratorId);
            $this->moveGroupMappings((int) $retiredRoleId, $administratorId);
            DB::table('role_has_permissions')
                ->where('role_id', $retiredRoleId)
                ->delete();
            DB::table('roles')->where('id', $retiredRoleId)->delete();
        }

        foreach (self::RETIRED_GROUPS as $group) {
            $this->mergeDirectoryGroup($group, self::ADMIN_GROUP);
        }

        $affectedUserIds = array_merge(
            $affectedUserIds,
            $this->rewriteUserColumns(),
        );
        $this->deleteRetiredPermission();
        $this->bumpAuthorizationEpochs($affectedUserIds);

        if ($reinstallGuard) {
            $this->guard->install(force: true);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function administratorRoleId(): int
    {
        $id = DB::table('roles')
            ->where('guard_name', 'web')
            ->where('name', AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        $now = now();

        return (int) DB::table('roles')->insertGetId([
            'name' => AuthorizationProfile::ROLE_ADMINISTRATOR,
            'guard_name' => 'web',
            'is_protected' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function moveRoleSources(int $fromRoleId, int $toRoleId): array
    {
        if (! Schema::hasTable('role_sources')) {
            return [];
        }

        $sources = DB::table('role_sources')
            ->where('role_id', $fromRoleId)
            ->get(['id', 'user_id', 'source_key']);

        foreach ($sources as $source) {
            $duplicate = DB::table('role_sources')
                ->where('user_id', $source->user_id)
                ->where('role_id', $toRoleId)
                ->where('source_key', $source->source_key)
                ->exists();

            $duplicate
                ? DB::table('role_sources')->where('id', $source->id)->delete()
                : DB::table('role_sources')
                    ->where('id', $source->id)
                    ->update(['role_id' => $toRoleId, 'updated_at' => now()]);
        }

        return $sources
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function moveModelRoles(int $fromRoleId, int $toRoleId): void
    {
        $rows = DB::table('model_has_roles')
            ->where('role_id', $fromRoleId)
            ->get(['model_type', 'model_id']);

        foreach ($rows as $row) {
            $duplicate = DB::table('model_has_roles')
                ->where('role_id', $toRoleId)
                ->where('model_type', $row->model_type)
                ->where('model_id', $row->model_id)
                ->exists();
            $query = DB::table('model_has_roles')
                ->where('role_id', $fromRoleId)
                ->where('model_type', $row->model_type)
                ->where('model_id', $row->model_id);

            $duplicate
                ? $query->delete()
                : $query->update(['role_id' => $toRoleId]);
        }
    }

    private function moveGroupMappings(int $fromRoleId, int $toRoleId): void
    {
        if (! Schema::hasTable('directory_group_role_mappings')) {
            return;
        }

        $mappings = DB::table('directory_group_role_mappings')
            ->where('role_id', $fromRoleId)
            ->get(['id', 'directory_group_id']);

        foreach ($mappings as $mapping) {
            $duplicate = DB::table('directory_group_role_mappings')
                ->where('directory_group_id', $mapping->directory_group_id)
                ->where('role_id', $toRoleId)
                ->exists();

            $duplicate
                ? DB::table('directory_group_role_mappings')->where('id', $mapping->id)->delete()
                : DB::table('directory_group_role_mappings')
                    ->where('id', $mapping->id)
                    ->update(['role_id' => $toRoleId, 'updated_at' => now()]);
        }
    }

    private function mergeDirectoryGroup(string $from, string $to): void
    {
        if (! Schema::hasTable('directory_groups')) {
            return;
        }

        $retired = DB::table('directory_groups')->where('name', $from)->first();

        if ($retired === null) {
            return;
        }

        $canonical = DB::table('directory_groups')->where('name', $to)->first();

        if ($canonical === null) {
            DB::table('directory_groups')
                ->where('id', $retired->id)
                ->update(['name' => $to, 'updated_at' => now()]);

            return;
        }

        $fromId = (int) $retired->id;
        $toId = (int) $canonical->id;

        if (Schema::hasTable('directory_group_role_mappings')) {
            $mappings = DB::table('directory_group_role_mappings')
                ->where('directory_group_id', $fromId)
                ->get(['id', 'role_id']);

            foreach ($mappings as $mapping) {
                $duplicate = DB::table('directory_group_role_mappings')
                    ->where('directory_group_id', $toId)
                    ->where('role_id', $mapping->role_id)
                    ->exists();

                $duplicate
                    ? DB::table('directory_group_role_mappings')->where('id', $mapping->id)->delete()
                    : DB::table('directory_group_role_mappings')
                        ->where('id', $mapping->id)
                        ->update(['directory_group_id' => $toId, 'updated_at' => now()]);
            }
        }

        if (Schema::hasTable('role_sources')) {
            $sources = DB::table('role_sources')
                ->where('directory_group_id', $fromId)
                ->get(['id', 'user_id', 'role_id', 'source_key']);

            foreach ($sources as $source) {
                $sourceKey = $source->source_key === "directory:{$fromId}"
                    ? "directory:{$toId}"
                    : (string) $source->source_key;
                $duplicate = DB::table('role_sources')
                    ->where('user_id', $source->user_id)
                    ->where('role_id', $source->role_id)
                    ->where('source_key', $sourceKey)
                    ->exists();

                $duplicate
                    ? DB::table('role_sources')->where('id', $source->id)->delete()
                    : DB::table('role_sources')
                        ->where('id', $source->id)
                        ->update([
                            'directory_group_id' => $toId,
                            'source_key' => $sourceKey,
                            'updated_at' => now(),
                        ]);
            }
        }

        DB::table('directory_groups')->where('id', $fromId)->delete();
    }

    /**
     * @return array<int, int>
     */
    private function rewriteUserColumns(): array
    {
        $affected = [];

        foreach (['legacy_access_level', 'role'] as $column) {
            if (! Schema::hasColumn('users', $column)) {
                continue;
            }

            $ids = DB::table('users')
                ->where($column, self::RETIRED_LEGACY_ACCESS_LEVEL)
                ->pluck('id')
                ->all();

            if ($ids !== []) {
                DB::table('users')
                    ->whereIn('id', $ids)
                    ->update([$column => 'admin']);
                $affected = array_merge($affected, $ids);
            }
        }

        if (Schema::hasColumn('users', 'ldap_groups')) {
            $users = DB::table('users')
                ->whereNotNull('ldap_groups')
                ->get(['id', 'ldap_groups']);

            foreach ($users as $user) {
                $groups = json_decode((string) $user->ldap_groups, true);

                if (! is_array($groups)) {
                    continue;
                }

                $normalized = array_map(
                    static fn (mixed $group): string => is_string($group) ? strtolower(trim($group)) : '',
                    $groups,
                );

                if (array_intersect($normalized, self::RETIRED_GROUPS) === []) {
                    continue;
                }

                $updated = array_map(
                    static fn (string $group): string => in_array($group, self::RETIRED_GROUPS, true)
                        ? self::ADMIN_GROUP
                        : $group,
                    array_filter($normalized, static fn (string $group): bool => $group !== ''),
                );
                $updated = array_values(array_unique($updated));
                sort($updated);

                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['ldap_groups' => json_encode($updated)]);
                $affected[] = (int) $user->id;
            }
        }

        return array_map('intval', $affected);
    }

    private function deleteRetiredPermission(): void
    {
        $permissionId = DB::table('permissions')
            ->where('guard_name', 'web')
            ->where('name', self::RETIRED_PERMISSION)
            ->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();

        if (Schema::hasTable('permission_sources')) {
            DB::table('permission_sources')->where('permission_id', $permissionId)->delete();
        }

        DB::table('permissions')->where('id', $permissionId)->delete();
    }

    /**
     * @param  array<int, int>  $userIds
     */
    private function bumpAuthorizationEpochs(array $userIds): void
    {
        $userIds = array_values(array_unique($userIds));

        if ($userIds === [] || ! Schema::hasColumn('users', 'authorization_epoch')) {
            return;
        }

        DB::table('users')
            ->whereIn('id', $userIds)
            ->increment('authorization_epoch');
    }
}
