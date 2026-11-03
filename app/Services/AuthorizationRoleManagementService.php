<?php

namespace App\Services;

use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\User;
use App\Exceptions\AuthorizationMutationDenied;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

class AuthorizationRoleManagementService
{
    public function __construct(
        private readonly AuthorizationProfile $profile,
        private readonly PrivilegedMutationAuthorizer $authorizer,
        private readonly AuditLogger $auditLogger,
        private readonly PermissionRegistrar $permissionRegistrar,
    ) {}

    public function runAsLocalQa(User $actor, callable $operation): mixed
    {
        return $this->authorizer->runAsLocalQa($actor, $operation);
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    public function create(
        User $actor,
        string $name,
        array $permissionNames,
    ): Role {
        try {
            return DB::transaction(function () use (
                $actor,
                $name,
                $permissionNames,
            ): Role {
                [$actor] = $this->authorizer->lock($actor);
                $this->assertManager($actor);
                $name = $this->validatedName($name);
                $permissions = $this->validatedPermissions($permissionNames);

                if (Role::query()
                    ->where('guard_name', 'web')
                    ->where('name', $name)
                    ->lockForUpdate()
                    ->exists()) {
                    $this->deny(
                        'role_name_unavailable',
                        'The role name is already in use.',
                    );
                }

                try {
                    $role = Role::query()->create([
                        'name' => $name,
                        'guard_name' => 'web',
                    ]);
                } catch (QueryException) {
                    $this->deny(
                        'role_name_unavailable',
                        'The role name is already in use.',
                    );
                }

                $this->replacePermissions($role, $permissions->pluck('id')->all());
                $this->permissionRegistrar->forgetCachedPermissions();
                $this->auditLogger->record(
                    'authorization.role_created',
                    $actor,
                    $role,
                    [
                        'role_id' => $role->id,
                        'permission_count' => $permissions->count(),
                    ],
                );

                return $role->fresh(['permissions']);
            });
        } catch (AuthorizationMutationDenied $exception) {
            $this->auditDenied($actor, 'role_create', $exception->reasonCode);

            throw $exception;
        }
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    public function update(
        User $actor,
        Role $role,
        string $name,
        array $permissionNames,
    ): Role {
        try {
            return DB::transaction(function () use (
                $actor,
                $role,
                $name,
                $permissionNames,
            ): Role {
                [$actor, $lockedUsers] = $this->authorizer->lock($actor);
                $this->assertManager($actor);
                $stored = Role::query()
                    ->whereKey($role->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertCustomRole($stored);
                $name = $this->validatedName($name);
                $permissions = $this->validatedPermissions($permissionNames);

                if (Role::query()
                    ->where('guard_name', 'web')
                    ->where('name', $name)
                    ->whereKeyNot($stored->getKey())
                    ->lockForUpdate()
                    ->exists()) {
                    $this->deny(
                        'role_name_unavailable',
                        'The role name is already in use.',
                    );
                }

                $beforePermissionIds = $stored->permissions()
                    ->orderBy('permissions.id')
                    ->pluck('permissions.id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();
                $afterPermissionIds = $permissions->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->sort()
                    ->values()
                    ->all();
                $permissionChanged = $beforePermissionIds !== $afterPermissionIds;
                $stored->forceFill(['name' => $name])->save();

                if ($permissionChanged) {
                    $affectedUsers = $this->assignedUsers(
                        $stored,
                        $lockedUsers,
                    );
                    $this->replacePermissions($stored, $afterPermissionIds);

                    foreach ($affectedUsers as $user) {
                        $this->invalidateAuthorization($user);
                    }

                    $this->permissionRegistrar->forgetCachedPermissions();
                    $this->auditLogger->record(
                        'authorization.role_permissions_changed',
                        $actor,
                        $stored,
                        [
                            'role_id' => $stored->id,
                            'permission_count' => count($afterPermissionIds),
                            'affected_user_count' => $affectedUsers->count(),
                        ],
                    );
                }

                $this->auditLogger->record(
                    'authorization.role_updated',
                    $actor,
                    $stored,
                    [
                        'role_id' => $stored->id,
                        'permission_count' => count($afterPermissionIds),
                    ],
                );

                return $stored->fresh(['permissions']);
            });
        } catch (AuthorizationMutationDenied $exception) {
            $this->auditDenied(
                $actor,
                'role_update',
                $exception->reasonCode,
                $role,
            );

            throw $exception;
        }
    }

    public function delete(User $actor, Role $role): void
    {
        try {
            DB::transaction(function () use ($actor, $role): void {
                [$actor] = $this->authorizer->lock($actor);
                $this->assertManager($actor);
                $stored = Role::query()
                    ->whereKey($role->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertCustomRole($stored);

                if (DB::table('role_sources')
                    ->where('role_id', $stored->id)
                    ->lockForUpdate()
                    ->exists()
                    || DB::table(config('permission.table_names.model_has_roles'))
                        ->where('role_id', $stored->id)
                        ->lockForUpdate()
                        ->exists()
                    || DB::table('directory_group_role_mappings')
                        ->where('role_id', $stored->id)
                        ->lockForUpdate()
                        ->exists()) {
                    $this->deny(
                        'role_is_assigned',
                        'Assigned custom roles cannot be deleted.',
                    );
                }

                $unsafePermissionExists = $stored->permissions()
                    ->where(function ($query): void {
                        $query
                            ->where('permissions.guard_name', '<>', 'web')
                            ->orWhere('permissions.is_code_owned', false)
                            ->orWhereNotIn(
                                'permissions.name',
                                $this->profile->permissions(),
                            );
                    })
                    ->exists();

                if ($unsafePermissionExists) {
                    $this->deny(
                        'role_has_unsafe_permissions',
                        'Roles with unsafe permission assignments cannot be deleted.',
                    );
                }

                $roleId = (int) $stored->id;
                DB::table(config('permission.table_names.role_has_permissions'))
                    ->where('role_id', $roleId)
                    ->delete();
                $stored->delete();
                $this->permissionRegistrar->forgetCachedPermissions();
                $this->auditLogger->record(
                    'authorization.role_deleted',
                    $actor,
                    null,
                    [
                        'role_id' => $roleId,
                    ],
                );
            });
        } catch (AuthorizationMutationDenied $exception) {
            $this->auditDenied(
                $actor,
                'role_delete',
                $exception->reasonCode,
                $role,
            );

            throw $exception;
        }
    }

    private function assertManager(User $actor): void
    {
        if (! $this->authorizer->authorizes(
            $actor,
            'admin.roles.manage',
        )) {
            $this->deny(
                'administrator_required',
                'Only an active administrator may manage roles.',
            );
        }
    }

    private function assertCustomRole(Role $role): void
    {
        if ($role->guard_name !== 'web' || $role->is_protected) {
            $this->deny(
                'protected_role',
                'Protected roles are read-only.',
            );
        }
    }

    private function validatedName(string $name): string
    {
        $name = trim($name);

        if (preg_match(
            '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/',
            $name,
        ) !== 1 || strlen($name) < 3 || strlen($name) > 64) {
            throw new InvalidArgumentException(
                'Role names must use lower kebab case and contain 3 to 64 characters.',
            );
        }

        if ($this->profile->isProtectedRole($name)) {
            throw new InvalidArgumentException(
                'Protected role names are reserved.',
            );
        }

        return $name;
    }

    /**
     * @param  array<int, string>  $permissionNames
     * @return Collection<int, Permission>
     */
    private function validatedPermissions(array $permissionNames)
    {
        $names = array_values(array_unique($permissionNames));
        sort($names);

        if (array_diff($names, $this->profile->permissions()) !== []) {
            throw new InvalidArgumentException(
                'Only code-owned catalogue permissions may be selected.',
            );
        }

        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->where('is_code_owned', true)
            ->whereIn('name', $names)
            ->orderBy('name')
            ->get();

        if ($permissions->count() !== count($names)) {
            throw new InvalidArgumentException(
                'Only code-owned catalogue permissions may be selected.',
            );
        }

        return $permissions;
    }

    /**
     * @param  array<int, int>  $permissionIds
     */
    private function replacePermissions(Role $role, array $permissionIds): void
    {
        DB::table(config('permission.table_names.role_has_permissions'))
            ->where('role_id', $role->id)
            ->delete();

        if ($permissionIds !== []) {
            DB::table(config('permission.table_names.role_has_permissions'))
                ->insert(array_map(
                    static fn (int $permissionId): array => [
                        'role_id' => $role->id,
                        'permission_id' => $permissionId,
                    ],
                    $permissionIds,
                ));
        }

        $role->unsetRelation('permissions');
    }

    /**
     * @return Collection<int, User>
     */
    private function assignedUsers(Role $role, Collection $lockedUsers)
    {
        $userIds = DB::table(config('permission.table_names.model_has_roles'))
            ->where('role_id', $role->id)
            ->where('model_type', (new User)->getMorphClass())
            ->pluck('model_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $lockedUsers
            ->filter(
                static fn (User $user): bool => in_array(
                    (int) $user->getKey(),
                    $userIds,
                    true,
                ),
            )
            ->values();
    }

    private function invalidateAuthorization(User $user): void
    {
        $user->forceFill([
            'authorization_epoch' => (int) $user->authorization_epoch + 1,
        ]);
        $user->setRememberToken(Str::random(60));
        $user->save();
    }

    private function deny(string $reasonCode, string $message): never
    {
        throw new AuthorizationMutationDenied($reasonCode, $message);
    }

    private function auditDenied(
        User $actor,
        string $action,
        string $reasonCode,
        ?Role $role = null,
    ): void {
        $context = [
            'action' => $action,
            'reason_code' => $reasonCode,
        ];

        if ($role?->getKey() !== null) {
            $context['role_id'] = (int) $role->getKey();
        }

        $this->auditLogger->record(
            'authorization.privileged_mutation_denied',
            $actor,
            null,
            $context,
        );
    }
}
