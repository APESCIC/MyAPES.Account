<?php

namespace App\Services;

use App\Core\Accounts\AuthorizationState;
use App\Core\Accounts\Permission;
use App\Core\Accounts\PermissionSource;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Exceptions\AuthorizationLifecycleException;
use App\Support\DirectoryImmutableMappings;
use App\Support\DirectoryLegacyGroupAliases;
use Illuminate\Support\Facades\DB;

class AuthorizationIntegrityChecker
{
    public function __construct(
        private readonly AuthorizationProfile $profile,
        private readonly AuthorizationPhaseBSchemaInspector $schema,
        private readonly AuthorizationDirectPermissionPolicy $directPermissions,
    ) {}

    /**
     * @return array{
     *     users: int,
     *     permissions: int,
     *     immutable_mappings: int,
     *     administrators: int
     * }
     */
    public function check(): array
    {
        $this->checkSchema();

        return DB::transaction(function (): array {
            $state = AuthorizationState::query()
                ->whereKey(AuthorizationState::SINGLETON_ID)
                ->lockForUpdate()
                ->first();
            User::query()
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $this->checkMetadata();
            $this->checkMappings();
            $users = $this->checkProvenance();
            $this->checkSessionCutover($state);
            $administrators = $this->effectiveAdministratorCount();

            if ($administrators < 1) {
                throw new AuthorizationLifecycleException(
                    'administrator_unavailable',
                );
            }

            return [
                'users' => $users,
                'permissions' => count($this->profile->permissions()),
                'immutable_mappings' => count(DirectoryImmutableMappings::all()),
                'administrators' => $administrators,
            ];
        });
    }

    private function checkSchema(): void
    {
        $this->schema->assertReady();
    }

    private function checkMetadata(): void
    {
        $expectedRoles = $this->profile->protectedRolesByPrecedence();
        sort($expectedRoles);
        $actualRoles = DB::table('roles')
            ->where('guard_name', 'web')
            ->where('is_protected', true)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if ($actualRoles !== $expectedRoles) {
            throw new AuthorizationLifecycleException('protected_roles');
        }

        $expectedPermissions = $this->profile->permissions();
        sort($expectedPermissions);
        $actualPermissions = DB::table('permissions')
            ->where('guard_name', 'web')
            ->where('is_code_owned', true)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if ($actualPermissions !== $expectedPermissions) {
            throw new AuthorizationLifecycleException('permission_matrix');
        }

        $actualMatrix = array_fill_keys(
            $this->profile->protectedRolesByPrecedence(),
            [],
        );
        $rows = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join(
                'permissions',
                'permissions.id',
                '=',
                'role_has_permissions.permission_id',
            )
            ->where('roles.guard_name', 'web')
            ->where('roles.is_protected', true)
            ->get([
                'roles.name as role_name',
                'permissions.name as permission_name',
            ]);

        foreach ($rows as $row) {
            $actualMatrix[$row->role_name][] = $row->permission_name;
        }

        foreach ($actualMatrix as &$permissions) {
            sort($permissions);
        }
        unset($permissions);
        $expectedMatrix = $this->profile->permissionMatrix();

        foreach ($expectedMatrix as &$permissions) {
            sort($permissions);
        }
        unset($permissions);
        ksort($actualMatrix);
        ksort($expectedMatrix);

        if ($actualMatrix !== $expectedMatrix) {
            throw new AuthorizationLifecycleException('permission_matrix');
        }

        $this->checkDirectPermissions();
    }

    private function checkDirectPermissions(): void
    {
        $sources = DB::table('permission_sources')
            ->join(
                'permissions',
                'permissions.id',
                '=',
                'permission_sources.permission_id',
            )
            ->get([
                'permission_sources.user_id',
                'permission_sources.permission_id',
                'permission_sources.team_id',
                'permission_sources.source',
                'permission_sources.source_key',
                'permission_sources.granted_by',
                'permissions.name',
                'permissions.guard_name',
            ]);
        $permissions = Permission::query()
            ->whereIn('id', $sources->pluck('permission_id'))
            ->get()
            ->keyBy('id');

        foreach ($sources as $source) {
            $permission = $permissions->get($source->permission_id);

            try {
                if ($permission === null
                    || $source->team_id !== null
                    || ! in_array(
                        $source->source,
                        PermissionSource::sources(),
                        true,
                    )
                    || $source->source_key !== $source->source
                    || ($source->source === PermissionSource::SOURCE_SYSTEM
                        && $source->granted_by !== null)
                    || ($source->source === PermissionSource::SOURCE_LOCAL
                        && $source->granted_by === null)) {
                    throw new \InvalidArgumentException;
                }

                $this->directPermissions->assertAssignable($permission);
            } catch (\InvalidArgumentException) {
                throw new AuthorizationLifecycleException(
                    'direct_user_permissions',
                );
            }
        }

        $sourcePairs = $sources
            ->map(
                static fn (object $source): string => $source->user_id
                    .':'.$source->permission_id,
            )
            ->unique()
            ->sort()
            ->values()
            ->all();
        $pivots = DB::table('model_has_permissions')
            ->where('model_type', (new User)->getMorphClass())
            ->get(['model_id', 'permission_id', 'team_id']);

        if ($pivots->contains(
            static fn (object $pivot): bool => $pivot->team_id !== null,
        )) {
            throw new AuthorizationLifecycleException(
                'direct_user_permissions',
            );
        }

        $pivotPairs = $pivots
            ->map(
                static fn (object $pivot): string => $pivot->model_id
                    .':'.$pivot->permission_id,
            )
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($sourcePairs !== $pivotPairs) {
            throw new AuthorizationLifecycleException(
                'direct_user_permissions',
            );
        }
    }

    private function checkMappings(): void
    {
        $mappings = DB::table('directory_group_role_mappings')
            ->leftJoin(
                'directory_groups',
                'directory_groups.id',
                '=',
                'directory_group_role_mappings.directory_group_id',
            )
            ->leftJoin(
                'roles',
                'roles.id',
                '=',
                'directory_group_role_mappings.role_id',
            )
            ->get([
                'directory_group_role_mappings.id',
                'directory_group_role_mappings.is_immutable',
                'directory_groups.name as group_name',
                'roles.name as role_name',
                'roles.guard_name',
            ]);
        $immutable = [];

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
                || ! is_string($mapping->role_name)
                || $mapping->role_name === ''
                || $mapping->guard_name !== 'web') {
                throw new AuthorizationLifecycleException(
                    'mapping_integrity',
                );
            }

            if ((bool) $mapping->is_immutable) {
                $immutable[] = [
                    'group_name' => $mapping->group_name,
                    'role_name' => $mapping->role_name,
                ];
            }
        }

        usort(
            $immutable,
            static fn (array $left, array $right): int => [
                $left['group_name'],
                $left['role_name'],
            ] <=> [
                $right['group_name'],
                $right['role_name'],
            ],
        );
        $expected = [];

        foreach (DirectoryImmutableMappings::all() as $groupName => $roleName) {
            $expected[] = [
                'group_name' => $groupName,
                'role_name' => $roleName,
            ];
        }

        usort(
            $expected,
            static fn (array $left, array $right): int => [
                $left['group_name'],
                $left['role_name'],
            ] <=> [
                $right['group_name'],
                $right['role_name'],
            ],
        );

        if (count($immutable) !== count(DirectoryImmutableMappings::all())
            || $immutable !== $expected) {
            throw new AuthorizationLifecycleException('mapping_integrity');
        }
    }

    private function checkProvenance(): int
    {
        $sources = DB::table('role_sources')
            ->join('roles', 'roles.id', '=', 'role_sources.role_id')
            ->leftJoin(
                'directory_groups',
                'directory_groups.id',
                '=',
                'role_sources.directory_group_id',
            )
            ->get([
                'role_sources.user_id',
                'role_sources.role_id',
                'role_sources.source',
                'role_sources.source_key',
                'role_sources.directory_group_id',
                'role_sources.granted_by',
                'roles.name as role_name',
                'roles.guard_name',
                'roles.is_protected',
                'directory_groups.name as directory_group_name',
            ]);
        $mappingPairs = DB::table('directory_group_role_mappings')
            ->get(['directory_group_id', 'role_id'])
            ->map(
                static fn (object $mapping): string => $mapping->directory_group_id
                    .':'.$mapping->role_id,
            )
            ->all();
        $usersById = User::query()
            ->get(['id', 'ldap_groups'])
            ->keyBy('id');
        $directoryProtectedUsers = $sources
            ->filter(
                static fn (object $source): bool => $source->source
                    === RoleSource::SOURCE_DIRECTORY
                    && (int) $source->is_protected === 1
                    && $source->role_name
                        !== AuthorizationProfile::ROLE_SERVICE_USER,
            )
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();

        foreach ($sources as $source) {
            $isDirectory = $source->source === RoleSource::SOURCE_DIRECTORY;
            $user = $usersById->get($source->user_id);
            $ldapGroups = $user?->ldap_groups ?? [];

            if (! in_array($source->source, RoleSource::sources(), true)
                || $source->guard_name !== 'web'
                || ($isDirectory
                    && ($source->directory_group_id === null
                        || $source->source_key
                            !== 'directory:'.$source->directory_group_id
                        || ! in_array(
                            $source->directory_group_id.':'.$source->role_id,
                            $mappingPairs,
                            true,
                        )
                        || ! is_array($ldapGroups)
                        || ! in_array(
                            $source->directory_group_name,
                            $ldapGroups,
                            true,
                        )
                        || ((int) $source->is_protected !== 1
                            && ! in_array(
                                (int) $source->user_id,
                                $directoryProtectedUsers,
                                true,
                            ))))
                || (! $isDirectory
                    && ($source->directory_group_id !== null
                        || $source->source_key !== $source->source))
                || ($source->source === RoleSource::SOURCE_LOCAL
                    && $source->granted_by === null)
                || ($source->source !== RoleSource::SOURCE_LOCAL
                    && $source->granted_by !== null)
                || ($source->source === RoleSource::SOURCE_LEGACY_COMPATIBILITY
                    && (int) $source->is_protected !== 1)) {
                throw new AuthorizationLifecycleException(
                    'provenance_integrity',
                );
            }
        }

        $sourcePairs = $sources
            ->map(
                static fn (object $source): string => $source->user_id.':'.$source->role_id,
            )
            ->unique()
            ->sort()
            ->values()
            ->all();
        $pivotPairs = DB::table('model_has_roles')
            ->where('model_type', (new User)->getMorphClass())
            ->get(['model_id', 'role_id'])
            ->map(
                static fn (object $pivot): string => $pivot->model_id.':'.$pivot->role_id,
            )
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($sourcePairs !== $pivotPairs) {
            throw new AuthorizationLifecycleException(
                'source_pivot_equality',
            );
        }

        $users = User::query()->with('roles')->orderBy('id')->get();

        foreach ($users as $user) {
            $protected = $user->roles
                ->filter(
                    fn ($role): bool => $role->guard_name === 'web'
                        && $this->profile->isProtectedRole($role->name),
                )
                ->pluck('name')
                ->unique()
                ->values()
                ->all();

            if (count($protected) !== 1) {
                throw new AuthorizationLifecycleException(
                    'protected_baseline',
                );
            }

            $expected = $this->profile->protectedRoleForLegacy(
                (string) $user->legacy_access_level,
            );

            if ($expected === null || $protected[0] !== $expected) {
                throw new AuthorizationLifecycleException(
                    'compatibility_mirror',
                );
            }

            $requiredLocalSystemRole = app()->environment(['local', 'testing'])
                ? $expected
                : AuthorizationProfile::ROLE_SERVICE_USER;

            if ($user->identity_type === User::IDENTITY_LOCAL
                && ! $sources->contains(
                    static fn (object $source): bool => (int) $source->user_id
                        === (int) $user->getKey()
                        && $source->source === RoleSource::SOURCE_SYSTEM
                        && $source->role_name
                            === $requiredLocalSystemRole,
                )) {
                throw new AuthorizationLifecycleException(
                    'provenance_integrity',
                );
            }
        }

        return $users->count();
    }

    private function checkSessionCutover(?AuthorizationState $state): void
    {
        if ($state === null
            || $state->cutover_completed_at === null
            || $state->session_cutover_completed_at === null) {
            throw new AuthorizationLifecycleException('session_cutover');
        }
    }

    private function effectiveAdministratorCount(): int
    {
        return User::query()
            ->eligibleAdministrators()
            ->count();
    }
}
