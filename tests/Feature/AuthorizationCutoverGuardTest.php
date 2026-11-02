<?php

namespace Tests\Feature;

use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Exceptions\AuthorizationLifecycleException;
use App\Services\AuthorizationRoleMaterializer;
use App\Support\AccessCompatibilityDatabaseGuard;
use App\Support\AuthorizationCompatibilityDatabaseGuard;
use App\Support\AuthorizationCutoverSchema;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ForwardOnlyDatabaseMigrations;
use Tests\TestCase;

class AuthorizationCutoverGuardTest extends TestCase
{
    use ForwardOnlyDatabaseMigrations;

    private MaintenanceMode $maintenanceMode;

    protected function afterRefreshingDatabase(): void
    {
        $this->maintenanceMode = $this->fakeMaintenanceMode();
    }

    public function test_guard_materializes_legacy_changes_without_disturbing_custom_or_other_sources(): void
    {
        $this->assertTrue(Schema::hasTable('role_sources'));

        DB::table('users')->insert($this->rolelessUser(
            100,
            'legacy@example.com',
            'staff',
        ));

        $staff = Role::query()->where('name', 'staff')->firstOrFail();
        $administrator = Role::query()->where('name', 'administrator')->firstOrFail();
        $custom = Role::query()->create([
            'name' => 'case-reviewer',
            'guard_name' => 'web',
            'is_protected' => false,
        ]);
        $user = User::query()->findOrFail(100);
        $materializer = app(AuthorizationRoleMaterializer::class);
        $materializer->grant($user, $custom, RoleSource::SOURCE_LOCAL, actor: $user);
        $materializer->grant($user, $staff, RoleSource::SOURCE_SYSTEM);

        DB::table('users')->where('id', $user->id)->update([
            'legacy_access_level' => 'admin',
        ]);

        $this->assertDatabaseHas('role_sources', [
            'user_id' => $user->id,
            'role_id' => $administrator->id,
            'source' => RoleSource::SOURCE_LEGACY_COMPATIBILITY,
        ]);
        $this->assertDatabaseMissing('role_sources', [
            'user_id' => $user->id,
            'role_id' => $staff->id,
            'source' => RoleSource::SOURCE_LEGACY_COMPATIBILITY,
        ]);
        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $custom->id,
            'model_id' => $user->id,
        ]);
        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $staff->id,
            'model_id' => $user->id,
        ]);
        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $administrator->id,
            'model_id' => $user->id,
        ]);
        $this->assertDatabaseCount('model_has_permissions', 0);
    }

    public function test_same_value_reconciliation_repairs_stale_protected_state_without_disturbing_custom_roles(): void
    {
        $user = User::factory()
            ->accessLevel(User::ROLE_ADMIN)
            ->create();
        $staff = Role::query()->where('name', 'staff')->firstOrFail();
        $administrator = Role::query()
            ->where('name', 'administrator')
            ->firstOrFail();
        $canonicalBefore = DB::table('role_sources')
            ->where('user_id', $user->id)
            ->where('role_id', $administrator->id)
            ->where(
                'source',
                RoleSource::SOURCE_LEGACY_COMPATIBILITY,
            )
            ->where(
                'source_key',
                RoleSource::SOURCE_LEGACY_COMPATIBILITY,
            )
            ->firstOrFail(['id', 'created_at', 'updated_at']);
        $custom = Role::query()->create([
            'name' => 'case-reviewer',
            'guard_name' => 'web',
            'is_protected' => false,
        ]);
        app(AuthorizationRoleMaterializer::class)->grant(
            $user,
            $custom,
            RoleSource::SOURCE_LOCAL,
            actor: $user,
        );

        DB::table('role_sources')->insert([
            'user_id' => $user->id,
            'role_id' => $staff->id,
            'source' => RoleSource::SOURCE_LEGACY_COMPATIBILITY,
            'source_key' => RoleSource::SOURCE_LEGACY_COMPATIBILITY,
            'directory_group_id' => null,
            'granted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $staff->id,
            'model_type' => 'user',
            'model_id' => $user->id,
        ]);

        app(AuthorizationCompatibilityDatabaseGuard::class)
            ->reconcileLegacySources();

        $this->assertSame(
            [$administrator->id],
            $this->canonicalProtectedSourceRoleIds($user),
        );
        $this->assertSame(
            [$administrator->id],
            $this->protectedPivotRoleIds($user),
        );
        $canonicalAfter = DB::table('role_sources')
            ->where('user_id', $user->id)
            ->where('role_id', $administrator->id)
            ->where(
                'source',
                RoleSource::SOURCE_LEGACY_COMPATIBILITY,
            )
            ->where(
                'source_key',
                RoleSource::SOURCE_LEGACY_COMPATIBILITY,
            )
            ->firstOrFail(['id', 'created_at', 'updated_at']);
        $this->assertEquals($canonicalBefore, $canonicalAfter);
        $this->assertDatabaseHas('role_sources', [
            'user_id' => $user->id,
            'role_id' => $custom->id,
            'source' => RoleSource::SOURCE_LOCAL,
        ]);
        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $custom->id,
            'model_type' => 'user',
            'model_id' => $user->id,
        ]);
    }

    public function test_cutover_fails_closed_when_exact_parity_finds_an_extra_protected_baseline(): void
    {
        $user = User::factory()
            ->accessLevel(User::ROLE_ADMIN)
            ->create();
        $migration = $this->migration();
        $migration->down();

        $this->app->bind(
            AuthorizationCompatibilityDatabaseGuard::class,
            static fn (): AuthorizationCompatibilityDatabaseGuard => new class extends AuthorizationCompatibilityDatabaseGuard
            {
                public function reconcileLegacySources(): void
                {
                    parent::reconcileLegacySources();

                    $userId = (int) DB::table('users')->min('id');
                    $staffRoleId = (int) DB::table('roles')
                        ->where('guard_name', 'web')
                        ->where('name', 'staff')
                        ->value('id');

                    DB::table('role_sources')->insert([
                        'user_id' => $userId,
                        'role_id' => $staffRoleId,
                        'source' => RoleSource::SOURCE_LEGACY_COMPATIBILITY,
                        'source_key' => RoleSource::SOURCE_LEGACY_COMPATIBILITY,
                        'directory_group_id' => null,
                        'granted_by' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    DB::table('model_has_roles')->insert([
                        'role_id' => $staffRoleId,
                        'model_type' => 'user',
                        'model_id' => $userId,
                    ]);
                }
            },
        );

        try {
            $migration->up();
            $this->fail('The cutover accepted an extra protected baseline.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Legacy authorization backfill parity verification failed.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(Schema::hasColumn('users', 'role'));
        $this->assertFalse(Schema::hasTable('role_sources'));
        $this->assertFalse(
            app(AuthorizationCompatibilityDatabaseGuard::class)->isInstalled(),
        );
        $this->assertTrue(
            app(AccessCompatibilityDatabaseGuard::class)->isInstalled(),
        );
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => User::ROLE_ADMIN,
            'legacy_access_level' => User::ROLE_ADMIN,
        ]);
    }

    public function test_cutover_fails_closed_when_canonical_legacy_metadata_is_not_null(): void
    {
        User::factory()
            ->accessLevel(User::ROLE_ADMIN)
            ->create();
        $migration = $this->migration();
        $migration->down();

        $this->app->bind(
            AuthorizationCompatibilityDatabaseGuard::class,
            static fn (): AuthorizationCompatibilityDatabaseGuard => new class extends AuthorizationCompatibilityDatabaseGuard
            {
                public function reconcileLegacySources(): void
                {
                    parent::reconcileLegacySources();

                    DB::table('role_sources')
                        ->where(
                            'source',
                            RoleSource::SOURCE_LEGACY_COMPATIBILITY,
                        )
                        ->where(
                            'source_key',
                            RoleSource::SOURCE_LEGACY_COMPATIBILITY,
                        )
                        ->update([
                            'directory_group_id' => DB::table(
                                'directory_groups',
                            )->min('id'),
                        ]);
                }
            },
        );

        try {
            $migration->up();
            $this->fail('The cutover accepted malformed canonical provenance.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Legacy authorization backfill parity verification failed.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(Schema::hasColumn('users', 'role'));
        $this->assertFalse(Schema::hasTable('role_sources'));
        $this->assertTrue(
            app(AccessCompatibilityDatabaseGuard::class)->isInstalled(),
        );
    }

    public function test_roleless_cutover_retry_repairs_stale_state_idempotently(): void
    {
        $user = User::factory()
            ->accessLevel(User::ROLE_ADMIN)
            ->create();
        $staff = Role::query()->where('name', 'staff')->firstOrFail();
        $administrator = Role::query()
            ->where('name', 'administrator')
            ->firstOrFail();
        $custom = Role::query()->create([
            'name' => 'case-reviewer',
            'guard_name' => 'web',
            'is_protected' => false,
        ]);
        app(AuthorizationRoleMaterializer::class)->grant(
            $user,
            $custom,
            RoleSource::SOURCE_LOCAL,
            actor: $user,
        );
        DB::table('role_sources')->insert([
            'user_id' => $user->id,
            'role_id' => $staff->id,
            'source' => RoleSource::SOURCE_LEGACY_COMPATIBILITY,
            'source_key' => RoleSource::SOURCE_LEGACY_COMPATIBILITY,
            'directory_group_id' => null,
            'granted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $staff->id,
            'model_type' => 'user',
            'model_id' => $user->id,
        ]);

        $migration = $this->migration();
        $migration->up();
        $firstSources = $this->roleSourceSemanticTuples($user);
        $firstPivots = $this->rolePivotSemanticTuples($user);
        $migration->up();

        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertTrue(
            app(AuthorizationCompatibilityDatabaseGuard::class)->isInstalled(),
        );
        $this->assertSame(
            [$administrator->id],
            $this->canonicalProtectedSourceRoleIds($user),
        );
        $this->assertSame(
            [$administrator->id],
            $this->protectedPivotRoleIds($user),
        );
        $this->assertSame(
            $firstSources,
            $this->roleSourceSemanticTuples($user),
        );
        $this->assertSame(
            $firstPivots,
            $this->rolePivotSemanticTuples($user),
        );
        $this->assertDatabaseHas('role_sources', [
            'user_id' => $user->id,
            'role_id' => $custom->id,
            'source' => RoleSource::SOURCE_LOCAL,
        ]);
        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $custom->id,
            'model_type' => 'user',
            'model_id' => $user->id,
        ]);
    }

    public function test_roleless_retry_does_not_reconcile_without_the_cutover_marker(): void
    {
        $user = User::factory()
            ->accessLevel(User::ROLE_ADMIN)
            ->create();
        $staff = Role::query()->where('name', 'staff')->firstOrFail();
        DB::table('role_sources')->insert([
            'user_id' => $user->id,
            'role_id' => $staff->id,
            'source' => RoleSource::SOURCE_LEGACY_COMPATIBILITY,
            'source_key' => RoleSource::SOURCE_LEGACY_COMPATIBILITY,
            'directory_group_id' => null,
            'granted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $staff->id,
            'model_type' => 'user',
            'model_id' => $user->id,
        ]);
        DB::table('authorization_states')
            ->where('id', 1)
            ->update(['cutover_completed_at' => null]);
        $beforeSources = DB::table('role_sources')->orderBy('id')->get();
        $beforePivots = DB::table('model_has_roles')
            ->orderBy('role_id')
            ->get();

        try {
            $this->migration()->up();
            $this->fail('The roleless retry accepted a missing cutover marker.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Authorization cutover completion marker verification failed.',
                $exception->getMessage(),
            );
        }

        $this->assertEquals(
            $beforeSources,
            DB::table('role_sources')->orderBy('id')->get(),
        );
        $this->assertEquals(
            $beforePivots,
            DB::table('model_has_roles')->orderBy('role_id')->get(),
        );
        $this->assertNull(
            DB::table('authorization_states')
                ->where('id', 1)
                ->value('cutover_completed_at'),
        );
        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertTrue(
            app(AuthorizationCompatibilityDatabaseGuard::class)->isInstalled(),
        );
    }

    public function test_roleless_retry_does_not_reconcile_an_incomplete_phase_b_schema(): void
    {
        $user = User::factory()
            ->accessLevel(User::ROLE_ADMIN)
            ->create();
        $staff = Role::query()->where('name', 'staff')->firstOrFail();
        DB::table('role_sources')->insert([
            'user_id' => $user->id,
            'role_id' => $staff->id,
            'source' => RoleSource::SOURCE_LEGACY_COMPATIBILITY,
            'source_key' => RoleSource::SOURCE_LEGACY_COMPATIBILITY,
            'directory_group_id' => null,
            'granted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $staff->id,
            'model_type' => 'user',
            'model_id' => $user->id,
        ]);
        Schema::drop('directory_sync_runs');

        try {
            $this->migration()->up();
            $this->fail('The roleless retry accepted an incomplete Phase B schema.');
        } catch (AuthorizationLifecycleException $exception) {
            $this->assertSame('authorization_schema', $exception->check);
        }

        $this->assertCount(
            2,
            $this->canonicalProtectedSourceRoleIds($user),
        );
        $this->assertCount(2, $this->protectedPivotRoleIds($user));
        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertTrue(
            app(AuthorizationCompatibilityDatabaseGuard::class)->isInstalled(),
        );
    }

    #[DataProvider('invalidAccessLevelProvider')]
    public function test_guard_rejects_invalid_legacy_inserts(?string $accessLevel): void
    {
        $this->assertTrue(Schema::hasTable('role_sources'));

        $this->expectException(QueryException::class);

        DB::table('users')->insert($this->rolelessUser(
            101,
            'invalid@example.com',
            $accessLevel,
        ));
    }

    public function test_guard_verifies_definitions_instead_of_trigger_names_only(): void
    {
        $this->assertTrue(Schema::hasTable('role_sources'));

        $guard = app(AuthorizationCompatibilityDatabaseGuard::class);
        $this->assertTrue($guard->isInstalled());
        $guard->drop();

        $this->installDummyNamedTriggers();

        $this->assertFalse($guard->isInstalled());

        $guard->install();
        $this->assertTrue($guard->isInstalled());
    }

    public function test_guard_rejects_a_stale_legacy_mapping_definition(): void
    {
        $guard = app(AuthorizationCompatibilityDatabaseGuard::class);
        $this->assertTrue($guard->isInstalled());

        $this->mutateUpdateTriggerMapping();

        try {
            $this->assertFalse($guard->isInstalled());
        } finally {
            $guard->drop();
            $guard->install();
        }
    }

    public function test_guard_accepts_legacy_user_fqcn_morph_expression_during_cutover(): void
    {
        $guard = app(AuthorizationCompatibilityDatabaseGuard::class);
        $this->assertTrue($guard->isInstalled());

        $this->rewriteUserMorphExpressionInTriggersToLegacyFqcn();

        try {
            $this->assertTrue($guard->isInstalled());
        } finally {
            $guard->drop();
            $guard->install();
        }
    }

    public function test_guard_detects_the_previous_generation_installation(): void
    {
        $guard = app(AuthorizationCompatibilityDatabaseGuard::class);
        $guard->upgrade();
        $this->downgradeAuthorizationGuardToPreviousGeneration();

        $this->assertTrue($guard->isLegacyInstalled());
        $this->assertFalse($guard->isInstalled());
    }

    public function test_guard_upgrade_refreshes_a_previous_generation_installation(): void
    {
        $guard = app(AuthorizationCompatibilityDatabaseGuard::class);
        $guard->upgrade();
        $this->downgradeAuthorizationGuardToPreviousGeneration();

        $guard->upgrade();

        $this->assertTrue($guard->isInstalled());
        $this->assertFalse($guard->isLegacyInstalled());
    }

    public function test_guard_model_type_is_independent_of_mysql_backslash_mode(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL SQL mode test.');
        }

        $guard = app(AuthorizationCompatibilityDatabaseGuard::class);
        $originalMode = (string) DB::scalar('SELECT @@SESSION.sql_mode');

        try {
            $guard->drop();
            DB::statement(
                "SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'NO_BACKSLASH_ESCAPES')",
            );
            $guard->install();

            DB::table('users')->insert($this->rolelessUser(
                102,
                'sql-mode@example.com',
                'staff',
            ));

            $this->assertSame(
                'user',
                DB::table('model_has_roles')
                    ->where('model_id', 102)
                    ->value('model_type'),
            );
        } finally {
            $guard->drop();
            DB::statement('SET SESSION sql_mode = ?', [$originalMode]);
            $guard->install();
        }
    }

    public function test_partial_guard_installation_is_cleaned_up(): void
    {
        $this->assertTrue(Schema::hasTable('role_sources'));

        $guard = new class extends AuthorizationCompatibilityDatabaseGuard
        {
            protected function installSqlite(): void
            {
                DB::unprepared(
                    'CREATE TRIGGER users_authorization_compatibility_insert
                     AFTER INSERT ON users
                     BEGIN
                         SELECT 1;
                     END',
                );

                throw new RuntimeException('Simulated partial guard installation.');
            }

            protected function installMysql(): void
            {
                DB::unprepared(
                    'CREATE TRIGGER users_authorization_compatibility_insert
                     AFTER INSERT ON users
                     FOR EACH ROW
                     SET @myapes_partial_guard = NEW.id',
                );

                throw new RuntimeException('Simulated partial guard installation.');
            }
        };
        $guard->drop();

        try {
            $guard->install();
            $this->fail('The guard accepted a partial installation.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Simulated partial guard installation.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, $this->authorizationTriggerCount());
    }

    public function test_guard_fails_closed_for_an_unsupported_driver(): void
    {
        $this->assertTrue(Schema::hasTable('role_sources'));

        $guard = new class extends AuthorizationCompatibilityDatabaseGuard
        {
            protected function driverName(): string
            {
                return 'pgsql';
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Authorization compatibility database guard is unsupported.');

        $guard->install();
    }

    public function test_upgrade_retry_and_deliberate_maintenance_rollback_are_safe(): void
    {
        $this->assertTrue(Schema::hasTable('role_sources'));

        $migration = $this->migration();
        $migration->down();
        $this->assertTrue(Schema::hasColumn('users', 'role'));
        $this->assertTrue(app(AccessCompatibilityDatabaseGuard::class)->isInstalled());
        $this->assertFalse(app(AuthorizationCompatibilityDatabaseGuard::class)->isInstalled());

        DB::table('users')->insert([
            'id' => 200,
            'oidc_sub' => null,
            'name' => 'Upgrade User',
            'email' => 'upgrade@example.com',
            'role' => 'admin',
            'password' => null,
            'created_at' => '2026-07-28 09:00:00',
            'updated_at' => '2026-07-28 09:00:00',
        ]);

        $this->app->bind(
            AuthorizationCompatibilityDatabaseGuard::class,
            static fn (): AuthorizationCompatibilityDatabaseGuard => new class extends AuthorizationCompatibilityDatabaseGuard
            {
                public function install(bool $force = false): void
                {
                    throw new RuntimeException('Simulated Phase B guard failure.');
                }
            },
        );

        try {
            $migration->up();
            $this->fail('The migration accepted a failed Phase B guard.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated Phase B guard failure.', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('users', 'role'));
        $this->assertFalse(Schema::hasColumn('users', 'authorization_epoch'));
        $this->assertFalse(Schema::hasTable('role_sources'));

        $this->app->offsetUnset(AuthorizationCompatibilityDatabaseGuard::class);
        $this->app->singleton(
            AuthorizationCompatibilityDatabaseGuard::class,
            AuthorizationCompatibilityDatabaseGuard::class,
        );
        $migration->up();

        $administrator = Role::query()->where('name', 'administrator')->firstOrFail();
        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertDatabaseHas('role_sources', [
            'user_id' => 200,
            'role_id' => $administrator->id,
            'source' => RoleSource::SOURCE_LEGACY_COMPATIBILITY,
        ]);
        $this->assertTrue(app(AuthorizationCompatibilityDatabaseGuard::class)->isInstalled());

        $migration->down();

        $this->assertDatabaseHas('users', [
            'id' => 200,
            'role' => 'admin',
            'legacy_access_level' => 'admin',
        ]);
        $this->assertTrue(app(AccessCompatibilityDatabaseGuard::class)->isInstalled());
        $this->assertFalse(app(AuthorizationCompatibilityDatabaseGuard::class)->isInstalled());
        $this->assertFalse(Schema::hasTable('role_sources'));
    }

    public function test_maintenance_downgrade_rejects_suspension_without_changing_schema(): void
    {
        User::factory()->create([
            'suspended_at' => now(),
            'suspension_reason' => 'Security hold',
        ]);

        try {
            $this->migration()->down();
            $this->fail('The downgrade accepted suspension state.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Maintenance downgrade cannot represent suspended accounts.',
                $exception->getMessage(),
            );
        }

        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertTrue(Schema::hasColumn('users', 'suspended_at'));
        $this->assertTrue(Schema::hasTable('role_sources'));

        DB::table('users')->update([
            'suspended_at' => null,
            'suspension_reason' => null,
        ]);
    }

    public function test_maintenance_downgrade_rejects_live_mode_before_mutation(): void
    {
        $user = User::factory()->create([
            'remember_token' => 'remember-token-canary',
        ]);
        $migration = $this->migration();
        $exception = null;

        $this->maintenanceMode->deactivate();

        try {
            $migration->down();
        } catch (RuntimeException $caught) {
            $exception = $caught;
        } finally {
            $roleWasAdded = Schema::hasColumn('users', 'role');
            $rememberToken = DB::table('users')
                ->where('id', $user->id)
                ->value('remember_token');
            $this->maintenanceMode->activate([]);

            if ($roleWasAdded) {
                $migration->up();
            }
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame(
            'Maintenance downgrade requires Laravel maintenance mode.',
            $exception->getMessage(),
        );
        $this->assertFalse($roleWasAdded);
        $this->assertSame('remember-token-canary', $rememberToken);
    }

    public function test_maintenance_downgrade_rejects_changed_epochs_without_changing_schema(): void
    {
        User::factory()->create(['authorization_epoch' => 2]);

        try {
            $this->migration()->down();
            $this->fail('The downgrade accepted changed authorization state.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Maintenance downgrade cannot represent changed authorization epochs.',
                $exception->getMessage(),
            );
        }

        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertTrue(Schema::hasColumn('users', 'authorization_epoch'));

        DB::table('users')->update(['authorization_epoch' => 1]);
    }

    public function test_permitted_maintenance_downgrade_invalidates_remember_tokens(): void
    {
        $user = User::factory()->create([
            'remember_token' => 'remember-token-canary',
        ]);

        $this->migration()->down();

        $this->assertNull(
            DB::table('users')->where('id', $user->id)->value('remember_token'),
        );
        $this->assertTrue(Schema::hasColumn('users', 'role'));
        $this->assertFalse(Schema::hasColumn('users', 'authorization_epoch'));
    }

    public function test_maintenance_downgrade_rejects_an_unsupported_session_backend_before_mutation(): void
    {
        $user = User::factory()->create([
            'remember_token' => 'remember-token-canary',
        ]);
        config(['session.driver' => 'cookie']);

        try {
            $this->migration()->down();
            $this->fail('The downgrade accepted an unsafe session backend.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Maintenance downgrade cannot safely invalidate the configured sessions.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            'remember-token-canary',
            DB::table('users')
                ->where('id', $user->id)
                ->value('remember_token'),
        );
        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertTrue(Schema::hasColumn('users', 'authorization_epoch'));

        config(['session.driver' => 'array']);
    }

    public function test_permitted_database_session_downgrade_deletes_all_sessions(): void
    {
        $user = User::factory()->create([
            'remember_token' => 'remember-token-canary',
        ]);
        DB::table('sessions')->insert([
            'id' => 'session-canary',
            'user_id' => $user->id,
            'ip_address' => null,
            'user_agent' => null,
            'payload' => 'session-payload-canary',
            'last_activity' => now()->timestamp,
        ]);
        config(['session.driver' => 'database']);

        $this->migration()->down();

        $this->assertDatabaseCount('sessions', 0);
        $this->assertNull(
            DB::table('users')
                ->where('id', $user->id)
                ->value('remember_token'),
        );
        $this->assertTrue(Schema::hasColumn('users', 'role'));
    }

    public function test_cutover_requires_the_phase_a_guard_before_schema_changes(): void
    {
        $migration = $this->migration();
        $migration->down();
        app(AccessCompatibilityDatabaseGuard::class)->drop();

        try {
            $migration->up();
            $this->fail('The cutover accepted a missing Phase A guard.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Phase A compatibility guard is required for authorization cutover.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(Schema::hasColumn('users', 'role'));
        $this->assertFalse(Schema::hasColumn('users', 'authorization_epoch'));
        $this->assertFalse(Schema::hasTable('authorization_states'));
    }

    public function test_cutover_requires_phase_a_role_mirror_parity_before_schema_changes(): void
    {
        $user = User::factory()->create();
        $migration = $this->migration();
        $migration->down();
        $phaseAGuard = app(AccessCompatibilityDatabaseGuard::class);
        $phaseAGuard->drop();
        DB::table('users')->where('id', $user->id)->update([
            'role' => 'admin',
            'legacy_access_level' => 'staff',
        ]);
        $phaseAGuard->install();

        try {
            $migration->up();
            $this->fail('The cutover accepted divergent Phase A role mirrors.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Phase A role mirror parity verification failed.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(Schema::hasColumn('users', 'role'));
        $this->assertFalse(Schema::hasColumn('users', 'authorization_epoch'));
        $this->assertFalse(Schema::hasTable('authorization_states'));
    }

    public function test_guard_preserves_nonprotected_transitional_sources(): void
    {
        $user = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'custom-legacy-import',
            'guard_name' => 'web',
            'is_protected' => false,
        ]);

        DB::table('role_sources')->insert([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'source' => RoleSource::SOURCE_LEGACY_COMPATIBILITY,
            'source_key' => 'custom-legacy-import',
            'directory_group_id' => null,
            'granted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => 'user',
            'model_id' => $user->id,
        ]);

        DB::table('users')->where('id', $user->id)->update([
            'legacy_access_level' => 'staff',
        ]);

        $this->assertDatabaseHas('role_sources', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'source' => RoleSource::SOURCE_LEGACY_COMPATIBILITY,
            'source_key' => 'custom-legacy-import',
        ]);
        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $role->id,
            'model_type' => 'user',
            'model_id' => $user->id,
        ]);
    }

    public function test_cutover_cleans_up_a_post_backfill_failure_and_retries(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->app->bind(
            AuthorizationCompatibilityDatabaseGuard::class,
            static fn (): AuthorizationCompatibilityDatabaseGuard => new class extends AuthorizationCompatibilityDatabaseGuard
            {
                private int $verificationCount = 0;

                public function isInstalled(): bool
                {
                    $installed = parent::isInstalled();
                    $this->verificationCount++;

                    return $this->verificationCount === 3 ? false : $installed;
                }
            },
        );

        try {
            $migration->up();
            $this->fail('The migration accepted a failed post-backfill verification.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Authorization compatibility database guard verification failed.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(Schema::hasColumn('users', 'role'));
        $this->assertFalse(Schema::hasColumn('users', 'authorization_epoch'));
        $this->assertFalse(Schema::hasTable('authorization_states'));

        $this->restoreAuthorizationGuardBinding();
        $migration->up();

        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertTrue(app(AuthorizationCompatibilityDatabaseGuard::class)->isInstalled());
    }

    public function test_cutover_recovers_when_phase_a_guard_drop_fails_after_ddl(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->app->bind(
            AccessCompatibilityDatabaseGuard::class,
            static fn (): AccessCompatibilityDatabaseGuard => new class extends AccessCompatibilityDatabaseGuard
            {
                private bool $shouldFail = true;

                public function drop(): void
                {
                    parent::drop();

                    if ($this->shouldFail) {
                        $this->shouldFail = false;

                        throw new RuntimeException('Simulated Phase A guard drop failure.');
                    }
                }
            },
        );

        try {
            $migration->up();
            $this->fail('The migration accepted a failed Phase A guard drop.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Simulated Phase A guard drop failure.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(Schema::hasColumn('users', 'role'));
        $this->assertTrue(app(AccessCompatibilityDatabaseGuard::class)->isInstalled());

        $this->restorePhaseAGuardBinding();
        $migration->up();

        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertTrue(app(AuthorizationCompatibilityDatabaseGuard::class)->isInstalled());
    }

    public function test_cutover_recovers_after_a_process_stops_between_phase_a_guard_and_role_removal(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->app->bind(
            AccessCompatibilityDatabaseGuard::class,
            static fn (): AccessCompatibilityDatabaseGuard => new class extends AccessCompatibilityDatabaseGuard
            {
                private bool $suppressRecovery = false;

                public function install(bool $force = false): void
                {
                    if ($this->suppressRecovery) {
                        return;
                    }

                    parent::install();
                }

                public function drop(): void
                {
                    parent::drop();
                    $this->suppressRecovery = true;
                }
            },
        );
        $this->app->instance(
            AuthorizationCutoverSchema::class,
            new class extends AuthorizationCutoverSchema
            {
                public function dropLegacyRoleColumn(): void
                {
                    throw new RuntimeException(
                        'Simulated process stop before role removal.',
                    );
                }
            },
        );

        try {
            $migration->up();
            $this->fail('The simulated process stop did not interrupt cutover.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Simulated process stop before role removal.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(Schema::hasColumn('users', 'role'));
        $this->assertFalse(
            app(AccessCompatibilityDatabaseGuard::class)->isInstalled(),
        );
        $this->assertTrue(
            app(AuthorizationCompatibilityDatabaseGuard::class)->isInstalled(),
        );
        $this->assertNotNull(
            DB::table('authorization_states')
                ->where('id', 1)
                ->value('cutover_completed_at'),
        );

        $this->restorePhaseAGuardBinding();
        $this->app->instance(
            AuthorizationCutoverSchema::class,
            new AuthorizationCutoverSchema,
        );
        $migration->up();

        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertFalse(
            app(AccessCompatibilityDatabaseGuard::class)->isInstalled(),
        );
        $this->assertTrue(
            app(AuthorizationCompatibilityDatabaseGuard::class)->isInstalled(),
        );
    }

    public function test_cutover_recovers_when_role_drop_fails_after_ddl(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->app->instance(
            AuthorizationCutoverSchema::class,
            new class extends AuthorizationCutoverSchema
            {
                private bool $shouldFail = true;

                public function dropLegacyRoleColumn(): void
                {
                    if ($this->shouldFail) {
                        $this->shouldFail = false;

                        throw new RuntimeException('Simulated legacy role drop failure.');
                    }

                    parent::dropLegacyRoleColumn();
                }
            },
        );

        try {
            $migration->up();
            $this->fail('The migration accepted a failed legacy role drop.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Simulated legacy role drop failure.',
                $exception->getMessage(),
            );
            $this->assertTrue(Schema::hasColumn('users', 'role'));
            $this->assertTrue(app(AccessCompatibilityDatabaseGuard::class)->isInstalled());
        }

        $migration->up();

        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertTrue(app(AuthorizationCompatibilityDatabaseGuard::class)->isInstalled());
    }

    public function test_cutover_retry_verifies_protected_roles_without_mutating_them(): void
    {
        $migration = $this->migration();
        $migration->down();
        Carbon::setTestNow('2026-07-28 11:00:00');
        $this->app->instance(
            AuthorizationCutoverSchema::class,
            new class extends AuthorizationCutoverSchema
            {
                private bool $shouldFail = true;

                public function dropLegacyRoleColumn(): void
                {
                    if ($this->shouldFail) {
                        $this->shouldFail = false;

                        throw new RuntimeException('Simulated metadata retry boundary.');
                    }

                    parent::dropLegacyRoleColumn();
                }
            },
        );

        try {
            $migration->up();
            $this->fail('The first cutover attempt unexpectedly completed.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Simulated metadata retry boundary.',
                $exception->getMessage(),
            );
        }

        $beforeRetry = DB::table('roles')
            ->where('guard_name', 'web')
            ->whereIn('name', [
                'service-user',
                'staff',
                'administrator',
                'super-admin',
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'guard_name', 'is_protected', 'created_at', 'updated_at'])
            ->map(static fn (object $role): array => (array) $role)
            ->all();

        try {
            Carbon::setTestNow('2026-07-28 12:00:00');
            $migration->up();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(
            $beforeRetry,
            DB::table('roles')
                ->where('guard_name', 'web')
                ->whereIn('name', [
                    'service-user',
                    'staff',
                    'administrator',
                    'super-admin',
                ])
                ->orderBy('name')
                ->get(['id', 'name', 'guard_name', 'is_protected', 'created_at', 'updated_at'])
                ->map(static fn (object $role): array => (array) $role)
                ->all(),
        );
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function invalidAccessLevelProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'blank' => ['   '],
            'unsupported' => ['owner'],
        ];
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path(
            'migrations/2026_07_28_000100_cut_over_authorization_domain.php',
        );

        return $migration;
    }

    private function installDummyNamedTriggers(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared(
                'CREATE TRIGGER users_authorization_compatibility_insert
                 AFTER INSERT ON users
                 BEGIN
                     SELECT 1;
                 END',
            );
            DB::unprepared(
                'CREATE TRIGGER users_authorization_compatibility_update
                 AFTER UPDATE OF legacy_access_level ON users
                 BEGIN
                     SELECT 1;
                 END',
            );

            return;
        }

        DB::unprepared(
            'CREATE TRIGGER users_authorization_compatibility_insert
             AFTER INSERT ON users
             FOR EACH ROW
             SET @myapes_dummy_insert = NEW.id',
        );
        DB::unprepared(
            'CREATE TRIGGER users_authorization_compatibility_update
             AFTER UPDATE ON users
             FOR EACH ROW
             SET @myapes_dummy_update = NEW.id',
        );
    }

    private function authorizationTriggerCount(): int
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return (int) DB::scalar(
                "SELECT COUNT(*) FROM sqlite_master
                 WHERE type = 'trigger'
                   AND name IN (
                       'users_authorization_compatibility_insert',
                       'users_authorization_compatibility_update'
                   )",
            );
        }

        return (int) DB::table('information_schema.triggers')
            ->where('trigger_schema', DB::connection()->getDatabaseName())
            ->where('event_object_table', 'users')
            ->whereIn('trigger_name', [
                'users_authorization_compatibility_insert',
                'users_authorization_compatibility_update',
            ])
            ->count();
    }

    private function mutateUpdateTriggerMapping(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $definition = (string) DB::scalar(
                "SELECT sql FROM sqlite_master
                 WHERE type = 'trigger'
                   AND name = 'users_authorization_compatibility_update'",
            );
            DB::unprepared('DROP TRIGGER users_authorization_compatibility_update');
            DB::unprepared(str_replace(
                "WHEN 'staff' THEN 'staff'",
                "WHEN 'staff' THEN 'administrator'",
                $definition,
            ));

            return;
        }

        $definition = (string) DB::table('information_schema.triggers')
            ->where('trigger_schema', DB::connection()->getDatabaseName())
            ->where('trigger_name', 'users_authorization_compatibility_update')
            ->value('action_statement');
        DB::unprepared('DROP TRIGGER users_authorization_compatibility_update');
        DB::unprepared(
            'CREATE TRIGGER users_authorization_compatibility_update
             AFTER UPDATE ON users
             FOR EACH ROW '.str_replace(
                "WHEN 'staff' THEN 'staff'",
                "WHEN 'staff' THEN 'administrator'",
                $definition,
            ),
        );
    }

    private function rewriteUserMorphExpressionInTriggersToLegacyFqcn(): void
    {
        $current = 'CHAR(117, 115, 101, 114)';
        $legacy = 'CHAR(65, 112, 112, 92, 77, 111, 100, 101, 108, 115, 92, 85, 115, 101, 114)';
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $triggers = DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->where('sql', 'like', '%'.$current.'%')
                ->get(['name', 'sql']);

            foreach ($triggers as $trigger) {
                DB::unprepared('DROP TRIGGER '.((string) $trigger->name));
                DB::unprepared(str_replace($current, $legacy, (string) $trigger->sql));
            }

            return;
        }

        // Alias columns: MySQL information_schema may expose UPPERCASE
        // attribute names when selected without aliases via the query builder.
        $triggers = collect(DB::select(
            'SELECT trigger_name AS name,
                    event_object_table AS table_name,
                    action_timing AS timing,
                    event_manipulation AS event_name,
                    action_statement AS definition
             FROM information_schema.triggers
             WHERE trigger_schema = DATABASE()
               AND action_statement LIKE ?',
            ['%'.$current.'%'],
        ));

        foreach ($triggers as $trigger) {
            $name = (string) $trigger->name;
            DB::unprepared("DROP TRIGGER {$name}");
            DB::unprepared(
                "CREATE TRIGGER {$name}
                 {$trigger->timing} {$trigger->event_name}
                 ON {$trigger->table_name}
                 FOR EACH ROW ".str_replace(
                    $current,
                    $legacy,
                    (string) $trigger->definition,
                ),
            );
        }
    }

    private function downgradeAuthorizationGuardToPreviousGeneration(): void
    {
        $driver = DB::connection()->getDriverName();
        $legacyLevels = "'service_user', 'staff', 'admin', 'superadmin'";
        $currentLevels = "'service_user', 'student', 'volunteer', 'staff', 'admin'";

        foreach ([
            'users_authorization_compatibility_insert',
            'users_authorization_compatibility_update',
        ] as $trigger) {
            if ($driver === 'sqlite') {
                $definition = (string) DB::scalar(
                    "SELECT sql FROM sqlite_master
                     WHERE type = 'trigger'
                       AND name = ?",
                    [$trigger],
                );
                DB::unprepared("DROP TRIGGER {$trigger}");
                DB::unprepared(str_replace($currentLevels, $legacyLevels, $definition));

                continue;
            }

            $definition = (string) DB::table('information_schema.triggers')
                ->where('trigger_schema', DB::connection()->getDatabaseName())
                ->where('trigger_name', $trigger)
                ->value('action_statement');
            $event = $trigger === 'users_authorization_compatibility_insert'
                ? 'INSERT'
                : 'UPDATE';
            DB::unprepared("DROP TRIGGER {$trigger}");
            DB::unprepared(
                "CREATE TRIGGER {$trigger}
                 AFTER {$event} ON users
                 FOR EACH ROW ".str_replace($currentLevels, $legacyLevels, $definition),
            );
        }
    }

    private function restoreAuthorizationGuardBinding(): void
    {
        $this->app->offsetUnset(AuthorizationCompatibilityDatabaseGuard::class);
        $this->app->singleton(
            AuthorizationCompatibilityDatabaseGuard::class,
            AuthorizationCompatibilityDatabaseGuard::class,
        );
    }

    private function restorePhaseAGuardBinding(): void
    {
        $this->app->offsetUnset(AccessCompatibilityDatabaseGuard::class);
        $this->app->singleton(
            AccessCompatibilityDatabaseGuard::class,
            AccessCompatibilityDatabaseGuard::class,
        );
    }

    /**
     * @return array<int, int>
     */
    private function canonicalProtectedSourceRoleIds(User $user): array
    {
        return DB::table('role_sources')
            ->join('roles', 'roles.id', '=', 'role_sources.role_id')
            ->where('role_sources.user_id', $user->id)
            ->where(
                'role_sources.source',
                RoleSource::SOURCE_LEGACY_COMPATIBILITY,
            )
            ->where(
                'role_sources.source_key',
                RoleSource::SOURCE_LEGACY_COMPATIBILITY,
            )
            ->where('roles.guard_name', 'web')
            ->where('roles.is_protected', true)
            ->orderBy('role_sources.role_id')
            ->pluck('role_sources.role_id')
            ->map(static fn (mixed $roleId): int => (int) $roleId)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function protectedPivotRoleIds(User $user): array
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', 'user')
            ->where('model_has_roles.model_id', $user->id)
            ->where('roles.guard_name', 'web')
            ->where('roles.is_protected', true)
            ->orderBy('model_has_roles.role_id')
            ->pluck('model_has_roles.role_id')
            ->map(static fn (mixed $roleId): int => (int) $roleId)
            ->all();
    }

    /**
     * @return array<int, array{int, int, string, string}>
     */
    private function roleSourceSemanticTuples(User $user): array
    {
        return DB::table('role_sources')
            ->where('user_id', $user->id)
            ->orderBy('role_id')
            ->orderBy('source')
            ->orderBy('source_key')
            ->get(['user_id', 'role_id', 'source', 'source_key'])
            ->map(static fn (object $source): array => [
                (int) $source->user_id,
                (int) $source->role_id,
                (string) $source->source,
                (string) $source->source_key,
            ])
            ->all();
    }

    /**
     * @return array<int, array{int, string, int}>
     */
    private function rolePivotSemanticTuples(User $user): array
    {
        return DB::table('model_has_roles')
            ->where('model_type', 'user')
            ->where('model_id', $user->id)
            ->orderBy('role_id')
            ->get(['role_id', 'model_type', 'model_id'])
            ->map(static fn (object $pivot): array => [
                (int) $pivot->role_id,
                (string) $pivot->model_type,
                (int) $pivot->model_id,
            ])
            ->all();
    }

    /**
     * @return array<string, int|string|null>
     */
    private function rolelessUser(int $id, string $email, ?string $accessLevel): array
    {
        return [
            'id' => $id,
            'oidc_sub' => null,
            'identity_type' => 'local',
            'legacy_access_level' => $accessLevel,
            'authorization_epoch' => 1,
            'name' => 'Roleless user',
            'email' => $email,
            'password' => null,
            'created_at' => '2026-07-28 09:00:00',
            'updated_at' => '2026-07-28 09:00:00',
        ];
    }
}
