<?php

namespace App\Services;

use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Exceptions\AuthorizationMutationDenied;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AuthorizationMutationService
{
    public function __construct(
        private readonly AuthorizationProfile $profile,
        private readonly PrivilegedMutationAuthorizer $authorizer,
        private readonly AuthorizationRoleMaterializer $materializer,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function suspend(
        User $target,
        User $actor,
        string $reason,
    ): void {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException(
                'Suspension reasons must contain between 1 and 500 characters.',
            );
        }

        try {
            DB::transaction(function () use ($target, $actor, $reason): void {
                [$target, $actor] = $this->lockUsers($target, $actor);
                $this->assertMayAffectTarget($target, $actor);

                if ($target->suspended_at !== null) {
                    return;
                }

                $this->assertNotFinalActiveAdministrator($target);
                $target->forceFill([
                    'suspended_at' => now(),
                    'suspended_by' => $actor->id,
                    'suspension_reason' => $reason,
                ]);
                $this->invalidateAuthorization($target);

                $this->auditLogger->record(
                    'authorization.user_suspended',
                    $actor,
                    $target,
                    [
                        'target_user_id' => $target->id,
                        'reason_length' => mb_strlen($reason),
                    ],
                );
            });
        } catch (AuthorizationMutationDenied $exception) {
            $this->auditDenied(
                $actor,
                $target,
                'suspend',
                $exception->reasonCode,
            );

            throw $exception;
        }
    }

    public function reactivate(User $target, User $actor): void
    {
        try {
            DB::transaction(function () use ($target, $actor): void {
                [$target, $actor] = $this->lockUsers($target, $actor);
                $this->assertMayAffectTarget($target, $actor);

                if ($target->suspended_at === null) {
                    return;
                }

                $target->forceFill([
                    'suspended_at' => null,
                    'suspended_by' => null,
                    'suspension_reason' => null,
                ]);
                $this->invalidateAuthorization($target);

                $this->auditLogger->record(
                    'authorization.user_reactivated',
                    $actor,
                    $target,
                    ['target_user_id' => $target->id],
                );
            });
        } catch (AuthorizationMutationDenied $exception) {
            $this->auditDenied(
                $actor,
                $target,
                'reactivate',
                $exception->reasonCode,
            );

            throw $exception;
        }
    }

    public function grantLocalRole(
        User $target,
        Role $role,
        User $actor,
    ): void {
        try {
            DB::transaction(function () use ($target, $role, $actor): void {
                [$target, $actor] = $this->lockUsers($target, $actor);
                $storedRole = $this->lockCustomRole($role);
                $this->assertMayAffectTarget($target, $actor);
                $beforeRoleIds = $this->effectiveRoleIds($target);

                $this->materializer->grant(
                    $target,
                    $storedRole,
                    RoleSource::SOURCE_LOCAL,
                    actor: $actor,
                );
                $target->refresh();

                if ($beforeRoleIds !== $this->effectiveRoleIds($target)) {
                    $this->invalidateAuthorization($target);
                }

                $this->auditLogger->record(
                    'authorization.local_role_granted',
                    $actor,
                    $target,
                    [
                        'target_user_id' => $target->id,
                        'role_id' => $storedRole->id,
                    ],
                );
            });
        } catch (AuthorizationMutationDenied $exception) {
            $this->auditDenied(
                $actor,
                $target,
                'grant_local_role',
                $exception->reasonCode,
                $role,
            );

            throw $exception;
        }
    }

    public function revokeLocalRole(
        User $target,
        Role $role,
        User $actor,
    ): void {
        try {
            DB::transaction(function () use ($target, $role, $actor): void {
                [$target, $actor] = $this->lockUsers($target, $actor);
                $storedRole = $this->lockCustomRole($role);
                $this->assertMayAffectTarget($target, $actor);
                $beforeRoleIds = $this->effectiveRoleIds($target);

                $this->materializer->revoke(
                    $target,
                    $storedRole,
                    RoleSource::SOURCE_LOCAL,
                );
                $target->refresh();
                $afterRoleIds = $this->effectiveRoleIds($target);

                if ($beforeRoleIds !== $afterRoleIds) {
                    $this->assertAnActiveAdministratorRemains();
                    $this->invalidateAuthorization($target);
                }

                $this->auditLogger->record(
                    'authorization.local_role_revoked',
                    $actor,
                    $target,
                    [
                        'target_user_id' => $target->id,
                        'role_id' => $storedRole->id,
                    ],
                );
            });
        } catch (AuthorizationMutationDenied $exception) {
            $this->auditDenied(
                $actor,
                $target,
                'revoke_local_role',
                $exception->reasonCode,
                $role,
            );

            throw $exception;
        }
    }

    /**
     * @param  array<int, Role>  $roles
     */
    public function synchronizeLocalRoles(
        User $target,
        array $roles,
        User $actor,
    ): void {
        try {
            DB::transaction(function () use ($target, $roles, $actor): void {
                [$target, $actor] = $this->lockUsers($target, $actor);
                $this->assertMayAffectTarget($target, $actor);
                $roleIds = array_values(array_unique(array_map(
                    static fn (Role $role): int => (int) $role->getKey(),
                    $roles,
                )));
                sort($roleIds);
                $storedRoles = Role::query()
                    ->whereKey($roleIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($storedRoles->count() !== count($roleIds)) {
                    $this->deny(
                        'invalid_role',
                        'Authorization roles must be persisted for the web guard.',
                    );
                }

                foreach ($storedRoles as $storedRole) {
                    $this->assertCustomRole($storedRole);
                }

                $existingSources = RoleSource::query()
                    ->with('role')
                    ->whereBelongsTo($target)
                    ->where('source', RoleSource::SOURCE_LOCAL)
                    ->orderBy('role_id')
                    ->lockForUpdate()
                    ->get();
                $beforeRoleIds = $this->effectiveRoleIds($target);
                $granted = 0;
                $revoked = 0;

                foreach ($existingSources as $source) {
                    if (in_array((int) $source->role_id, $roleIds, true)) {
                        continue;
                    }

                    $this->materializer->revoke(
                        $target,
                        $source->getRelation('role'),
                        RoleSource::SOURCE_LOCAL,
                    );
                    $revoked++;
                }

                $existingRoleIds = $existingSources
                    ->pluck('role_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();

                foreach ($storedRoles as $storedRole) {
                    if (in_array((int) $storedRole->id, $existingRoleIds, true)) {
                        continue;
                    }

                    $this->materializer->grant(
                        $target,
                        $storedRole,
                        RoleSource::SOURCE_LOCAL,
                        actor: $actor,
                    );
                    $granted++;
                }

                $target->refresh();

                if ($beforeRoleIds !== $this->effectiveRoleIds($target)) {
                    $this->invalidateAuthorization($target);
                }

                $this->auditLogger->record(
                    'authorization.local_roles_synchronized',
                    $actor,
                    $target,
                    [
                        'target_user_id' => $target->id,
                        'role_ids' => $roleIds,
                        'role_count' => count($roleIds),
                        'granted_count' => $granted,
                        'revoked_count' => $revoked,
                    ],
                );
            });
        } catch (AuthorizationMutationDenied $exception) {
            $this->auditDenied(
                $actor,
                $target,
                'synchronize_local_roles',
                $exception->reasonCode,
            );

            throw $exception;
        }
    }

    public function canManageTarget(User $actor, User $target): bool
    {
        if ($actor->suspended_at !== null || $target->is($actor)) {
            return false;
        }

        $actorRole = $this->profile->effectiveProtectedRole($actor);
        $targetRole = $this->profile->effectiveProtectedRole($target);

        return $actorRole === AuthorizationProfile::ROLE_ADMINISTRATOR
            && $targetRole !== null;
    }

    private function assertMayAffectTarget(User $target, User $actor): void
    {
        if ($target->is($actor)) {
            $this->deny(
                'self_change',
                'Actors cannot change their own authorization state.',
            );
        }

        $actorRole = $this->profile->effectiveProtectedRole($actor);
        $targetRole = $this->profile->effectiveProtectedRole($target);

        if (! $this->authorizer->authorizes(
            $actor,
            'admin.users.manage',
        )
            || $actorRole !== AuthorizationProfile::ROLE_ADMINISTRATOR
            || ! $this->authorizer->isEligibleAdministrator($actor)) {
            $this->deny(
                'active_administrator_required',
                'Only an active administrator may manage users.',
            );
        }

        if ($targetRole === null) {
            $this->deny(
                'unsupported_target',
                'The target has no protected authorization baseline.',
            );
        }
    }

    private function assertNotFinalActiveAdministrator(User $target): void
    {
        if (! $this->authorizer->isEligibleAdministrator($target)) {
            return;
        }

        if ($this->authorizer->eligibleAdministratorCount() <= 1) {
            $this->deny(
                'final_active_administrator',
                'At least one active administrator must remain.',
            );
        }
    }

    private function assertAnActiveAdministratorRemains(): void
    {
        $this->authorizer->assertEligibleAdministratorRemains();
    }

    private function lockCustomRole(Role $role): Role
    {
        $stored = Role::query()
            ->whereKey($role->getKey())
            ->lockForUpdate()
            ->first();

        if ($stored === null) {
            $this->deny(
                'invalid_role',
                'Authorization roles must be persisted for the web guard.',
            );
        }

        $this->assertCustomRole($stored);

        return $stored;
    }

    private function assertCustomRole(Role $role): void
    {
        if ($role->guard_name !== 'web') {
            $this->deny(
                'invalid_role',
                'Authorization roles must be persisted for the web guard.',
            );
        }

        if ($role->is_protected) {
            $this->deny(
                'protected_local_role',
                'Protected roles cannot be assigned locally.',
            );
        }
    }

    private function invalidateAuthorization(User $user): void
    {
        $user->forceFill([
            'authorization_epoch' => (int) $user->authorization_epoch + 1,
        ]);
        $user->setRememberToken(Str::random(60));
        $user->save();
    }

    /**
     * @return array<int, int>
     */
    private function effectiveRoleIds(User $user): array
    {
        $ids = $user->roles()
            ->pluck('roles.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        sort($ids);

        return $ids;
    }

    /**
     * Authorization mutation lock order:
     * the complete user set by ascending ID, then directory groups/roles/mappings, then
     * provenance and permission pivots.
     *
     * Locking every user before returning fresh actor and target models means
     * later active-administrator invariant reads cannot acquire user locks after
     * role, provenance, or permission-pivot locks.
     *
     * @return array{User, User}
     */
    private function lockUsers(User $target, User $actor): array
    {
        [$lockedActor, $users] = $this->authorizer->lock($actor);
        $lockedTarget = $users->first(
            static fn (User $user): bool => $user->getKey() === $target->getKey(),
        );
        if (! $lockedTarget instanceof User) {
            throw (new ModelNotFoundException)->setModel(
                User::class,
                [$target->getKey()],
            );
        }

        return [$lockedTarget, $lockedActor];
    }

    private function deny(string $reasonCode, string $message): never
    {
        throw new AuthorizationMutationDenied($reasonCode, $message);
    }

    private function auditDenied(
        User $actor,
        User $target,
        string $action,
        string $reasonCode,
        ?Role $role = null,
    ): void {
        $context = [
            'action' => $action,
            'target_user_id' => (int) $target->getKey(),
            'reason_code' => $reasonCode,
        ];

        if ($role?->getKey() !== null) {
            $context['role_id'] = (int) $role->getKey();
        }

        $this->auditLogger->record(
            'authorization.privileged_mutation_denied',
            $actor,
            $target,
            $context,
        );
    }
}
