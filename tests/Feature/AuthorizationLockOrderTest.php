<?php

namespace Tests\Feature;

use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Exceptions\AuthorizationLifecycleException;
use App\Services\AuthorizationIntegrityChecker;
use App\Services\AuthorizationMutationService;
use App\Services\AuthorizationRoleManagementService;
use App\Services\AuthorizationRoleMaterializer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthorizationLockOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_integrity_check_locks_state_and_complete_user_set_before_data_reads(): void
    {
        User::factory()
            ->accessLevel(User::ROLE_ADMIN)
            ->create();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = str_replace(
                ['"', '`'],
                '',
                strtolower($query->sql),
            );
        });

        try {
            app(AuthorizationIntegrityChecker::class)->check();
        } catch (AuthorizationLifecycleException) {
            // This fixture proves lock order before the later lifecycle gate.
        }

        $stateLock = $this->firstQueryIndex(
            $queries,
            static fn (string $sql): bool => str_contains(
                $sql,
                'from authorization_states',
            ),
        );
        $completeUserLock = $this->firstQueryIndex(
            $queries,
            static fn (string $sql): bool => str_contains(
                $sql,
                'from users order by id asc',
            ),
        );
        $firstProvenanceRead = $this->firstQueryIndex(
            $queries,
            static fn (string $sql): bool => str_contains(
                $sql,
                'from role_sources',
            ),
        );

        $this->assertNotNull($stateLock);
        $this->assertNotNull($completeUserLock);
        $this->assertNotNull($firstProvenanceRead);
        $this->assertLessThan($completeUserLock, $stateLock);
        $this->assertLessThan($firstProvenanceRead, $completeUserLock);

        if (DB::getDriverName() !== 'sqlite') {
            $this->assertStringContainsString(
                'for update',
                $queries[$stateLock],
            );
            $this->assertStringContainsString(
                'for update',
                $queries[$completeUserLock],
            );
        }
    }

    public function test_role_permission_updates_lock_the_complete_user_set_before_the_role(): void
    {
        $actor = User::factory()
            ->accessLevel(User::ROLE_ADMIN)
            ->create()
            ->refresh();
        $target = User::factory()
            ->accessLevel(User::ROLE_SERVICE_USER)
            ->create()
            ->refresh();
        $role = Role::query()->create([
            'name' => 'case-reviewer',
            'guard_name' => 'web',
        ]);
        app(AuthorizationRoleMaterializer::class)->grant(
            $target,
            $role,
            RoleSource::SOURCE_LOCAL,
            actor: $actor,
        );
        $this->actingAs($actor);
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = str_replace(
                ['"', '`'],
                '',
                strtolower($query->sql),
            );
        });

        app(AuthorizationRoleManagementService::class)->update(
            $actor,
            $role,
            $role->name,
            ['admin.users.view'],
        );

        $completeUserLock = $this->firstQueryIndex(
            $queries,
            static fn (string $sql): bool => str_contains(
                $sql,
                'select * from users order by id asc',
            ),
        );
        $roleLock = $this->firstQueryIndex(
            $queries,
            static fn (string $sql): bool => str_contains(
                $sql,
                'select * from roles',
            ) && str_contains($sql, 'roles.id = ?'),
        );
        $stateLock = $this->firstQueryIndex(
            $queries,
            static fn (string $sql): bool => str_contains(
                $sql,
                'from authorization_states',
            ),
        );

        $this->assertNotNull($stateLock);
        $this->assertNotNull(
            $completeUserLock,
            'Role updates did not establish the complete ordered user lock.',
        );
        $this->assertNotNull($roleLock, 'Role updates did not lock the role.');
        $this->assertLessThan($completeUserLock, $stateLock);
        $this->assertLessThan($roleLock, $completeUserLock);

        if (DB::getDriverName() !== 'sqlite') {
            $this->assertStringContainsString(
                'for update',
                $queries[$completeUserLock],
            );
            $this->assertStringContainsString(
                'for update',
                $queries[$roleLock],
            );
        }
    }

    #[DataProvider('userMutationOperations')]
    public function test_user_mutations_lock_the_complete_user_set_before_later_authorization_queries(
        string $operation,
        bool $expectsRoleLock,
        bool $expectsActiveSuperAdminQuery,
    ): void {
        $actor = User::factory()
            ->accessLevel(User::ROLE_ADMIN)
            ->create()
            ->refresh();
        $target = User::factory()
            ->accessLevel(
                $operation === 'suspend'
                    ? User::ROLE_ADMIN
                    : User::ROLE_SERVICE_USER,
            )
            ->create()
            ->refresh();
        $currentRole = Role::query()->create([
            'name' => 'case-reviewer',
            'guard_name' => 'web',
        ]);
        $replacementRole = Role::query()->create([
            'name' => 'welfare-reviewer',
            'guard_name' => 'web',
        ]);
        $materializer = app(AuthorizationRoleMaterializer::class);

        if (in_array($operation, ['revoke', 'replace'], true)) {
            $materializer->grant(
                $target,
                $currentRole,
                RoleSource::SOURCE_LOCAL,
                actor: $actor,
            );
        }

        if ($operation === 'reactivate') {
            DB::table('users')
                ->where('id', $target->id)
                ->update([
                    'suspended_at' => now(),
                    'suspended_by' => $actor->id,
                    'suspension_reason' => 'Lock-order fixture.',
                ]);
        }

        $this->actingAs($actor);
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = str_replace(
                ['"', '`'],
                '',
                strtolower($query->sql),
            );
        });
        $service = app(AuthorizationMutationService::class);

        match ($operation) {
            'suspend' => $service->suspend(
                $target,
                $actor,
                'Lock-order contract.',
            ),
            'reactivate' => $service->reactivate($target, $actor),
            'grant' => $service->grantLocalRole(
                $target,
                $currentRole,
                $actor,
            ),
            'revoke' => $service->revokeLocalRole(
                $target,
                $currentRole,
                $actor,
            ),
            'replace' => $service->synchronizeLocalRoles(
                $target,
                [$replacementRole],
                $actor,
            ),
        };

        $completeUserLock = $this->firstQueryIndex(
            $queries,
            static fn (string $sql): bool => str_contains(
                $sql,
                'select * from users order by id asc',
            ),
        );
        $firstUserQuery = $this->firstQueryIndex(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'from users'),
        );
        $stateLock = $this->firstQueryIndex(
            $queries,
            static fn (string $sql): bool => str_contains(
                $sql,
                'from authorization_states',
            ),
        );

        $this->assertNotNull(
            $stateLock,
            "{$operation} did not establish the authorization-state lock.",
        );
        $this->assertNotNull(
            $completeUserLock,
            "{$operation} did not establish the complete ordered user lock.",
        );
        $this->assertSame($completeUserLock, $firstUserQuery);
        $this->assertLessThan($completeUserLock, $stateLock);

        if ($expectsRoleLock) {
            $roleLock = $this->firstQueryIndex(
                $queries,
                static fn (string $sql): bool => str_contains(
                    $sql,
                    'select * from roles',
                ) && str_contains($sql, 'roles.id'),
            );
            $this->assertNotNull(
                $roleLock,
                "{$operation} did not lock its custom role.",
            );
            $this->assertLessThan($roleLock, $completeUserLock);
        }

        if ($expectsActiveSuperAdminQuery) {
            $activeSuperAdminQuery = $this->firstQueryIndex(
                $queries,
                static fn (string $sql): bool => str_contains(
                    $sql,
                    'from users',
                ) && str_contains($sql, 'suspended_at is null'),
            );
            $this->assertNotNull(
                $activeSuperAdminQuery,
                "{$operation} did not revalidate the active super-admin invariant.",
            );
            $this->assertLessThan(
                $activeSuperAdminQuery,
                $completeUserLock,
            );
        }

        if (DB::getDriverName() !== 'sqlite') {
            $this->assertStringContainsString(
                'for update',
                $queries[$completeUserLock],
            );
        }
    }

    /**
     * @return array<string, array{string, bool, bool}>
     */
    public static function userMutationOperations(): array
    {
        return [
            'suspension' => ['suspend', false, true],
            'reactivation' => ['reactivate', false, false],
            'local role grant' => ['grant', true, false],
            'local role revocation' => ['revoke', true, true],
            'local role replacement' => ['replace', true, false],
        ];
    }

    /**
     * @param  array<int, string>  $queries
     */
    private function firstQueryIndex(array $queries, callable $matches): ?int
    {
        foreach ($queries as $index => $query) {
            if ($matches($query)) {
                return $index;
            }
        }

        return null;
    }
}
