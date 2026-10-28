<?php

namespace Tests\Feature;

use App\Core\Accounts\AuthorizationState;
use App\Core\Accounts\DirectoryGroup;
use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Exceptions\DirectoryIdentityNotFound;
use App\Exceptions\DirectoryUnavailable;
use App\Services\AuthorizationActivationSynchronizer;
use App\Services\AuthorizationPreflightChecker;
use App\Services\AuthorizationRoleMaterializer;
use App\Services\DirectoryUserSynchronizer;
use App\Services\LdapGroupResolver;
use App\Support\AuthorizationCompatibilityDatabaseGuard;
use App\Support\DirectoryGroupPrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Fakes\FakeDirectoryUserSynchronizer;
use Tests\TestCase;
use Throwable;

class AuthorizationLifecycleCommandTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://my.cloudron.apes.org.uk/openid';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://myaccount.myapes.me.uk',
            'myapes.oidc.issuer' => self::ISSUER,
            'myapes.oidc.client_id' => 'oidc-client-id-canary',
            'myapes.oidc.client_secret' => 'oidc-client-secret-canary',
            'myapes.oidc.redirect_uri' => 'https://myaccount.myapes.me.uk/staff/auth/callback',
            'myapes.oidc.scopes' => ['openid', 'profile', 'email'],
            'myapes.directory.required_groups' => [
                'myapesaccount.staff',
                'myapesaccount.admin',
                'myapesaccount.superadmin',
                'myapesaccount.volunteer',
                'myapesaccount.student',
            ],
        ]);
        $this->app->instance(
            DirectoryUserSynchronizer::class,
            new FakeDirectoryUserSynchronizer,
        );
        Http::preventStrayRequests();
        Http::fake([
            'https://my.cloudron.apes.org.uk/.well-known/openid-configuration' => Http::response(
                $this->validMetadata(),
            ),
        ]);
        $this->installDirectory();
    }

    public function test_preflight_accepts_the_fully_cut_over_phase_b_schema(): void
    {
        $superAdmin = User::factory()
            ->accessLevel(User::ROLE_SUPERADMIN)
            ->cloudronIdentity('oidc-subject-canary-preflight-phase-b')
            ->create([
                'email' => 'preflight-super-person-canary@example.test',
            ]);
        $this->directory()->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];

        $exitCode = $this->callCommand('myapes:authorization-preflight');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Database driver: ok', $output);
        $this->assertStringContainsString('Authorization schema: ok (phase_b)', $output);
        $this->assertStringContainsString('OIDC readiness: ok', $output);
        $this->assertStringContainsString('Directory groups: ok (5 groups)', $output);
        $this->assertStringContainsString(
            'Eligible OIDC super-admins: ok (1 users)',
            $output,
        );
        $this->assertStringContainsString('Authorization preflight: ok (1 users)', $output);
        $this->assertCanariesAreAbsent($output);
    }

    public function test_preflight_accepts_legacy_phase_b_guard_when_upgrade_migration_is_pending(): void
    {
        $guard = app(AuthorizationCompatibilityDatabaseGuard::class);
        DB::table('migrations')
            ->where(
                'migration',
                '2026_08_24_000003_extend_directory_authorization_roles',
            )
            ->delete();

        try {
            $guard->upgrade();
            $this->downgradeAuthorizationGuardToPreviousGeneration();

            $superAdmin = User::factory()
                ->accessLevel(User::ROLE_SUPERADMIN)
                ->cloudronIdentity('oidc-subject-canary-preflight-legacy-guard')
                ->create([
                    'email' => 'preflight-legacy-guard-person-canary@example.test',
                ]);
            $this->directory()->groupsByEmail = [
                $superAdmin->email => ['myapesaccount.superadmin'],
            ];

            $exitCode = $this->callCommand('myapes:authorization-preflight');
            $output = Artisan::output();

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString(
                'Authorization schema: ok (phase_b)',
                $output,
            );
            $this->assertStringContainsString(
                'Authorization preflight: ok (1 users)',
                $output,
            );
            $this->assertCanariesAreAbsent($output);
        } finally {
            $guard->upgrade();

            if (! DB::table('migrations')
                ->where(
                    'migration',
                    '2026_08_24_000003_extend_directory_authorization_roles',
                )
                ->exists()) {
                DB::table('migrations')->insert([
                    'migration' => '2026_08_24_000003_extend_directory_authorization_roles',
                    'batch' => 1,
                ]);
            }
        }
    }

    public function test_preflight_accepts_legacy_myapes_superadmin_group_membership(): void
    {
        $superAdmin = User::factory()
            ->accessLevel(User::ROLE_SUPERADMIN)
            ->cloudronIdentity('oidc-subject-canary-preflight-legacy-groups')
            ->create([
                'email' => 'preflight-legacy-groups-person-canary@example.test',
            ]);
        $this->directory()->groupsByEmail = [
            $superAdmin->email => ['myapes.superadmins'],
        ];

        $exitCode = $this->callCommand('myapes:authorization-preflight');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Directory groups: ok (5 groups)', $output);
        $this->assertStringContainsString(
            'Eligible OIDC super-admins: ok (1 users)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_preflight_is_safe_before_phase_b_migrations_and_checks_phase_a_parity(): void
    {
        $superAdmin = User::factory()
            ->accessLevel(User::ROLE_SUPERADMIN)
            ->cloudronIdentity('oidc-subject-canary-preflight-phase-a')
            ->create([
                'email' => 'preflight-phase-a-super-person-canary@example.test',
            ]);
        $this->directory()->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $this->runInMaintenanceMode(
            fn () => $this->phaseBMigration()->down(),
        );

        $exitCode = $this->callCommand('myapes:authorization-preflight');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Authorization schema: ok (phase_a)', $output);
        $this->assertStringContainsString('Legacy parity: ok (1 users)', $output);
        $this->assertStringContainsString('Directory groups: ok (5 groups)', $output);
        $this->assertStringContainsString(
            'Eligible OIDC super-admins: ok (1 users)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_preflight_rejects_when_no_existing_user_has_an_oidc_subject(): void
    {
        User::factory()->accessLevel(User::ROLE_SUPERADMIN)->create([
            'email' => 'local-super-person-canary@example.test',
        ]);

        $exitCode = $this->callCommand('myapes:authorization-preflight');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization preflight: failed (super_admin_unavailable)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_preflight_rejects_when_the_only_oidc_user_is_missing_from_ldap(): void
    {
        $candidate = User::factory()
            ->accessLevel(User::ROLE_SUPERADMIN)
            ->cloudronIdentity('oidc-subject-canary-preflight-missing')
            ->create([
                'email' => 'missing-preflight-person-canary@example.test',
            ]);
        $this->directory()->missingEmails = [$candidate->email];

        $exitCode = $this->callCommand('myapes:authorization-preflight');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization preflight: failed (super_admin_unavailable)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_preflight_requires_exact_super_admin_group_membership(): void
    {
        $candidate = User::factory()
            ->accessLevel(User::ROLE_SUPERADMIN)
            ->cloudronIdentity('oidc-subject-canary-preflight-wrong-group')
            ->create([
                'email' => 'wrong-group-preflight-person-canary@example.test',
            ]);
        $this->directory()->groupsByEmail = [
            $candidate->email => ['myapesaccount.superadmin.legacy'],
        ];

        $exitCode = $this->callCommand('myapes:authorization-preflight');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization preflight: failed (super_admin_unavailable)',
            $output,
        );
        $this->assertStringNotContainsString(
            'myapesaccount.superadmin.legacy',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_phase_b_preflight_excludes_a_suspended_oidc_super_admin(): void
    {
        $candidate = User::factory()
            ->accessLevel(User::ROLE_SUPERADMIN)
            ->cloudronIdentity('oidc-subject-canary-preflight-suspended')
            ->create([
                'email' => 'suspended-preflight-person-canary@example.test',
                'suspended_at' => now(),
            ]);
        $this->directory()->groupsByEmail = [
            $candidate->email => ['myapesaccount.superadmin'],
        ];

        $exitCode = $this->callCommand('myapes:authorization-preflight');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization preflight: failed (super_admin_unavailable)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_preflight_fails_closed_and_sanitizes_an_oidc_user_ldap_outage(): void
    {
        $candidate = User::factory()
            ->accessLevel(User::ROLE_SUPERADMIN)
            ->cloudronIdentity('oidc-subject-canary-preflight-outage')
            ->create([
                'email' => 'outage-preflight-person-canary@example.test',
            ]);
        $this->directory()->failuresByEmail[$candidate->email] = new DirectoryUnavailable(
            'ldap-password-canary for outage-preflight-person-canary@example.test',
        );

        $exitCode = $this->callCommand('myapes:authorization-preflight');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization preflight: failed (directory_readiness/ldap_directory:unavailable)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_preflight_rejects_a_tampered_phase_b_guard_without_internal_details(): void
    {
        $guard = app(AuthorizationCompatibilityDatabaseGuard::class);
        $guard->drop();

        try {
            $exitCode = $this->callCommand('myapes:authorization-preflight');
            $output = Artisan::output();

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString(
                'Authorization preflight: failed (authorization_schema)',
                $output,
            );
            $this->assertCanariesAreAbsent($output);
        } finally {
            $guard->install();
        }
    }

    public function test_preflight_rejects_an_incomplete_phase_b_index_inventory_before_ldap(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique(['name', 'guard_name']);
        });

        $exitCode = $this->callCommand('myapes:authorization-preflight');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization preflight: failed (authorization_schema)',
            $output,
        );
        $this->assertSame(0, $this->directory()->enumerationCalls);
        $this->assertCanariesAreAbsent($output);
    }

    public function test_preflight_rejects_a_missing_task_4_timestamp_before_ldap(): void
    {
        Schema::table('directory_sync_runs', function (Blueprint $table): void {
            $table->dropColumn('updated_at');
        });

        $exitCode = $this->callCommand('myapes:authorization-preflight');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization preflight: failed (authorization_schema)',
            $output,
        );
        $this->assertSame(0, $this->directory()->enumerationCalls);
        $this->assertCanariesAreAbsent($output);
    }

    public function test_preflight_sanitizes_an_unexpected_verification_failure(): void
    {
        $this->mock(
            AuthorizationPreflightChecker::class,
            function (MockInterface $mock): void {
                $mock->shouldReceive('check')
                    ->once()
                    ->andThrow(new RuntimeException(
                        'database-host-canary person-canary@example.test',
                    ));
            },
        );

        $exitCode = $this->callCommand('myapes:authorization-preflight');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization preflight: failed (verification_failed)',
            $output,
        );
        $this->assertStringNotContainsString('database-host-canary', $output);
        $this->assertStringNotContainsString('person-canary@example.test', $output);
    }

    public function test_authorization_sync_sanitizes_an_unexpected_service_failure(): void
    {
        $this->mock(
            AuthorizationActivationSynchronizer::class,
            function (MockInterface $mock): void {
                $mock->shouldReceive('synchronize')
                    ->once()
                    ->andThrow(new RuntimeException(
                        'database-host-canary oidc-subject-canary',
                    ));
            },
        );

        $exitCode = $this->callCommand('myapes:authorization-sync');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization sync: failed (synchronization_failed)',
            $output,
        );
        $this->assertStringNotContainsString('database-host-canary', $output);
        $this->assertStringNotContainsString('oidc-subject-canary', $output);
    }

    public function test_initial_authorization_sync_repairs_metadata_reconciles_identities_and_rotates_once(): void
    {
        [$local, $superAdmin, $missing] = $this->activationUsers();
        $directory = $this->directory();
        $directory->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $directory->missingEmails = [$missing->email];
        $before = collect([$local, $superAdmin, $missing])->mapWithKeys(
            static fn (User $user): array => [$user->id => [
                'epoch' => $user->authorization_epoch,
                'remember_token' => $user->getRememberToken(),
            ]],
        );
        $this->tamperSynchronizableMetadata();

        $exitCode = $this->callCommand('myapes:authorization-sync');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            'Authorization sync: ok (3 users, 2 directory identities)',
            $output,
        );
        $this->assertStringContainsString('Session cutover: complete (3 users)', $output);
        $this->assertCanariesAreAbsent($output);
        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => Role::query()->where('name', 'staff')->value('id'),
            'permission_id' => DB::table('permissions')
                ->where('name', 'staff.access')
                ->value('id'),
        ]);
        $this->assertDatabaseHas('directory_group_role_mappings', [
            'directory_group_id' => DirectoryGroup::query()
                ->where('name', 'myapesaccount.superadmin')
                ->value('id'),
            'role_id' => Role::query()->where('name', 'super-admin')->value('id'),
            'is_immutable' => true,
        ]);

        $local->refresh();
        $this->assertSame('service_user', $local->legacy_access_level);
        $this->assertSame(['service-user'], $local->roles()->pluck('name')->all());
        $this->assertDatabaseHas('role_sources', [
            'user_id' => $local->id,
            'role_id' => Role::query()->where('name', 'service-user')->value('id'),
            'source' => RoleSource::SOURCE_SYSTEM,
        ]);

        $superAdmin->refresh();
        $this->assertSame('superadmin', $superAdmin->legacy_access_level);
        $this->assertSame(['super-admin'], $superAdmin->roles()->pluck('name')->all());
        $this->assertDatabaseHas('role_sources', [
            'user_id' => $superAdmin->id,
            'role_id' => Role::query()->where('name', 'super-admin')->value('id'),
            'source' => RoleSource::SOURCE_DIRECTORY,
        ]);

        $missing->refresh();
        $this->assertSame('service_user', $missing->legacy_access_level);
        $this->assertSame(['service-user'], $missing->roles()->pluck('name')->all());
        $this->assertDatabaseMissing('role_sources', [
            'user_id' => $missing->id,
            'source' => RoleSource::SOURCE_DIRECTORY,
        ]);

        foreach ([$local, $superAdmin, $missing] as $user) {
            $user->refresh();
            $this->assertSame(
                $before[$user->id]['epoch'] + 1,
                $user->authorization_epoch,
            );
            $this->assertNotSame(
                $before[$user->id]['remember_token'],
                $user->getRememberToken(),
            );
        }
        $this->assertNotNull(
            AuthorizationState::query()
                ->findOrFail(AuthorizationState::SINGLETON_ID)
                ->session_cutover_completed_at,
        );
    }

    public function test_repeated_authorization_sync_is_idempotent_for_session_cutover(): void
    {
        [$local, $superAdmin, $missing] = $this->activationUsers();
        $directory = $this->directory();
        $directory->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $directory->missingEmails = [$missing->email];
        $this->assertSame(0, $this->callCommand('myapes:authorization-sync'));
        $state = AuthorizationState::query()
            ->findOrFail(AuthorizationState::SINGLETON_ID);
        $snapshot = User::query()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(static fn (User $user): array => [$user->id => [
                'epoch' => $user->authorization_epoch,
                'remember_token' => $user->getRememberToken(),
            ]]);

        $exitCode = $this->callCommand('myapes:authorization-sync');

        $this->assertSame(0, $exitCode);
        foreach (User::query()->orderBy('id')->get() as $user) {
            $this->assertSame($snapshot[$user->id]['epoch'], $user->authorization_epoch);
            $this->assertSame(
                $snapshot[$user->id]['remember_token'],
                $user->getRememberToken(),
            );
        }
        $this->assertSame(
            $state->session_cutover_completed_at?->toISOString(),
            AuthorizationState::query()
                ->findOrFail(AuthorizationState::SINGLETON_ID)
                ->session_cutover_completed_at
                ?->toISOString(),
        );
    }

    public function test_phase_b_compatibility_guard_still_materializes_a_write_after_activation(): void
    {
        [$local, $superAdmin, $missing] = $this->activationUsers();
        $directory = $this->directory();
        $directory->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $directory->missingEmails = [$missing->email];
        $this->assertSame(0, $this->callCommand('myapes:authorization-sync'));

        DB::table('users')
            ->where('id', $local->id)
            ->update([
                'legacy_access_level' => User::ROLE_SERVICE_USER,
                'updated_at' => now(),
            ]);

        $serviceRoleId = Role::query()
            ->where('name', 'service-user')
            ->value('id');
        $this->assertDatabaseHas('role_sources', [
            'user_id' => $local->id,
            'role_id' => $serviceRoleId,
            'source' => RoleSource::SOURCE_LEGACY_COMPATIBILITY,
            'source_key' => RoleSource::SOURCE_LEGACY_COMPATIBILITY,
        ]);
        $this->assertDatabaseHas('model_has_roles', [
            'model_id' => $local->id,
            'role_id' => $serviceRoleId,
            'model_type' => 'user',
        ]);
        $this->assertTrue(
            app(AuthorizationCompatibilityDatabaseGuard::class)
                ->isInstalled(),
        );
    }

    public function test_repeated_authorization_sync_repairs_stale_compatibility_state_and_preserves_custom_roles(): void
    {
        [, $superAdmin, $missing] = $this->activationUsers();
        $directory = $this->directory();
        $directory->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $directory->missingEmails = [$missing->email];
        $this->assertSame(0, $this->callCommand('myapes:authorization-sync'));

        $staff = Role::query()->where('name', 'staff')->firstOrFail();
        $superAdminRole = Role::query()
            ->where('name', 'super-admin')
            ->firstOrFail();
        $custom = Role::query()->create([
            'name' => 'case-reviewer',
            'guard_name' => 'web',
            'is_protected' => false,
        ]);
        app(AuthorizationRoleMaterializer::class)->grant(
            $superAdmin,
            $custom,
            RoleSource::SOURCE_LOCAL,
            actor: $superAdmin,
        );
        DB::table('role_sources')->insert([
            'user_id' => $superAdmin->id,
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
            'model_id' => $superAdmin->id,
        ]);

        $this->assertSame(0, $this->callCommand('myapes:authorization-sync'));

        $this->assertSame(
            [$superAdminRole->id],
            DB::table('role_sources')
                ->join('roles', 'roles.id', '=', 'role_sources.role_id')
                ->where('role_sources.user_id', $superAdmin->id)
                ->where(
                    'role_sources.source',
                    RoleSource::SOURCE_LEGACY_COMPATIBILITY,
                )
                ->where(
                    'role_sources.source_key',
                    RoleSource::SOURCE_LEGACY_COMPATIBILITY,
                )
                ->where('roles.is_protected', true)
                ->orderBy('role_sources.role_id')
                ->pluck('role_sources.role_id')
                ->map(static fn (mixed $roleId): int => (int) $roleId)
                ->all(),
        );
        $this->assertSame(
            [$superAdminRole->id],
            DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_type', 'user')
                ->where('model_has_roles.model_id', $superAdmin->id)
                ->where('roles.is_protected', true)
                ->orderBy('model_has_roles.role_id')
                ->pluck('model_has_roles.role_id')
                ->map(static fn (mixed $roleId): int => (int) $roleId)
                ->all(),
        );
        $this->assertDatabaseHas('role_sources', [
            'user_id' => $superAdmin->id,
            'role_id' => $custom->id,
            'source' => RoleSource::SOURCE_LOCAL,
        ]);
        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $custom->id,
            'model_type' => 'user',
            'model_id' => $superAdmin->id,
        ]);
    }

    public function test_directory_outage_aborts_authorization_sync_before_activation(): void
    {
        [, $superAdmin] = $this->activationUsers();
        $directory = $this->directory();
        $directory->failuresByEmail = [
            $superAdmin->email => new DirectoryUnavailable(
                'ldap-password-canary for identity-canary',
            ),
        ];
        $beforeUsers = DB::table('users')->orderBy('id')->get();
        $beforeSources = DB::table('role_sources')->orderBy('id')->get();

        $exitCode = $this->callCommand('myapes:authorization-sync');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization sync: failed (directory_unavailable)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
        $this->assertEquals($beforeUsers, DB::table('users')->orderBy('id')->get());
        $this->assertEquals(
            $beforeSources,
            DB::table('role_sources')->orderBy('id')->get(),
        );
        $this->assertNull(
            AuthorizationState::query()
                ->findOrFail(AuthorizationState::SINGLETON_ID)
                ->session_cutover_completed_at,
        );
    }

    public function test_authorization_sync_rejects_a_changed_identity_snapshot_without_activation_mutation(): void
    {
        [$local, $superAdmin, $missing] = $this->activationUsers();
        $directory = $this->directory();
        $directory->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $directory->missingEmails = [$missing->email];
        $sourcesBefore = DB::table('role_sources')->orderBy('id')->get();
        $pivotsBefore = DB::table('model_has_roles')
            ->orderBy('model_id')
            ->orderBy('role_id')
            ->get();
        $usersBefore = collect([$local, $superAdmin, $missing])->mapWithKeys(
            static fn (User $user): array => [$user->id => $user->only([
                'legacy_access_level',
                'authorization_epoch',
                'remember_token',
            ])],
        );
        $directory->beforeResolutionReturn = static function () use ($superAdmin): void {
            DB::table('users')
                ->where('id', $superAdmin->id)
                ->update([
                    'email' => 'changed-identity-canary@example.test',
                    'updated_at' => now(),
                ]);
        };

        $exitCode = $this->callCommand('myapes:authorization-sync');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization sync: failed (authorization_snapshot_changed)',
            $output,
        );
        $this->assertSame(
            'changed-identity-canary@example.test',
            $superAdmin->fresh()->email,
        );
        foreach ($usersBefore as $id => $snapshot) {
            $this->assertSame(
                $snapshot,
                User::query()->findOrFail($id)->only(array_keys($snapshot)),
            );
        }
        $this->assertEquals(
            $sourcesBefore,
            DB::table('role_sources')->orderBy('id')->get(),
        );
        $this->assertEquals(
            $pivotsBefore,
            DB::table('model_has_roles')
                ->orderBy('model_id')
                ->orderBy('role_id')
                ->get(),
        );
        $this->assertNull(
            AuthorizationState::query()
                ->findOrFail(AuthorizationState::SINGLETON_ID)
                ->session_cutover_completed_at,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_authorization_sync_rejects_a_changed_complete_user_set_without_activation_mutation(): void
    {
        [$local, $superAdmin, $missing] = $this->activationUsers();
        $directory = $this->directory();
        $directory->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $directory->missingEmails = [$missing->email];
        $originalUserIds = collect([$local, $superAdmin, $missing])
            ->pluck('id')
            ->all();
        $sourcesBefore = DB::table('role_sources')
            ->whereIn('user_id', $originalUserIds)
            ->orderBy('id')
            ->get();
        $pivotsBefore = DB::table('model_has_roles')
            ->whereIn('model_id', $originalUserIds)
            ->orderBy('model_id')
            ->orderBy('role_id')
            ->get();
        $directory->beforeResolutionReturn = static function (): void {
            User::factory()
                ->accessLevel(User::ROLE_SERVICE_USER)
                ->create([
                    'email' => 'racing-user-canary@example.test',
                ]);
        };

        $exitCode = $this->callCommand('myapes:authorization-sync');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization sync: failed (authorization_snapshot_changed)',
            $output,
        );
        $this->assertDatabaseHas('users', [
            'email' => 'racing-user-canary@example.test',
        ]);
        $this->assertEquals(
            $sourcesBefore,
            DB::table('role_sources')
                ->whereIn('user_id', $originalUserIds)
                ->orderBy('id')
                ->get(),
        );
        $this->assertEquals(
            $pivotsBefore,
            DB::table('model_has_roles')
                ->whereIn('model_id', $originalUserIds)
                ->orderBy('model_id')
                ->orderBy('role_id')
                ->get(),
        );
        $this->assertNull(
            AuthorizationState::query()
                ->findOrFail(AuthorizationState::SINGLETON_ID)
                ->session_cutover_completed_at,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_authorization_sync_fails_closed_without_an_effective_super_admin(): void
    {
        $local = User::factory()
            ->accessLevel(User::ROLE_ADMIN)
            ->create()
            ->refresh();
        $before = $local->only([
            'legacy_access_level',
            'authorization_epoch',
            'remember_token',
        ]);

        $exitCode = $this->callCommand('myapes:authorization-sync');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization sync: failed (super_admin_unavailable)',
            $output,
        );
        $this->assertSame(
            $before,
            $local->fresh()->only([
                'legacy_access_level',
                'authorization_epoch',
                'remember_token',
            ]),
        );
        $this->assertNull(
            AuthorizationState::query()
                ->findOrFail(AuthorizationState::SINGLETON_ID)
                ->session_cutover_completed_at,
        );
    }

    public function test_authorization_sync_rejects_invalid_existing_mappings_before_activation(): void
    {
        [, $superAdmin, $missing] = $this->activationUsers();
        $directory = $this->directory();
        $directory->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $directory->missingEmails = [$missing->email];
        $wildcard = DirectoryGroup::query()->create([
            'name' => 'myapes.*',
            'status' => DirectoryGroup::STATUS_PRESENT,
        ]);
        DB::table('directory_group_role_mappings')->insert([
            'directory_group_id' => $wildcard->id,
            'role_id' => Role::query()->where('name', 'staff')->value('id'),
            'is_immutable' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $beforeUsers = DB::table('users')->orderBy('id')->get();

        $exitCode = $this->callCommand('myapes:authorization-sync');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization sync: failed (mapping_integrity)',
            $output,
        );
        $this->assertStringNotContainsString('myapes.*', $output);
        $this->assertEquals(
            $beforeUsers,
            DB::table('users')->orderBy('id')->get(),
        );
        $this->assertNull(
            AuthorizationState::query()
                ->findOrFail(AuthorizationState::SINGLETON_ID)
                ->session_cutover_completed_at,
        );
    }

    public function test_authorization_check_is_read_only_after_activation_and_reports_only_counts(): void
    {
        [$local, $superAdmin, $missing] = $this->activationUsers();
        $directory = $this->directory();
        $directory->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $directory->missingEmails = [$missing->email];
        $this->assertSame(0, $this->callCommand('myapes:authorization-sync'));
        $before = [
            'users' => DB::table('users')->orderBy('id')->get(),
            'sources' => DB::table('role_sources')->orderBy('id')->get(),
            'state' => DB::table('authorization_states')->get(),
        ];

        $exitCode = $this->callCommand('myapes:authorization-check');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Authorization schema: ok', $output);
        $this->assertStringContainsString('Permission matrix: ok (83 permissions)', $output);
        $this->assertStringContainsString('Directory mappings: ok (5 immutable)', $output);
        $this->assertStringContainsString('Role provenance: ok (3 users)', $output);
        $this->assertStringContainsString('Session cutover: ok', $output);
        $this->assertStringContainsString('Effective super-admins: ok (1 users)', $output);
        $this->assertStringContainsString('Authorization check: ok (3 users)', $output);
        $this->assertCanariesAreAbsent($output);
        $this->assertEquals($before['users'], DB::table('users')->orderBy('id')->get());
        $this->assertEquals(
            $before['sources'],
            DB::table('role_sources')->orderBy('id')->get(),
        );
        $this->assertEquals($before['state'], DB::table('authorization_states')->get());
    }

    public function test_authorization_check_rejects_local_role_provenance_without_an_actor(): void
    {
        [$local, $superAdmin, $missing] = $this->activationUsers();
        $directory = $this->directory();
        $directory->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $directory->missingEmails = [$missing->email];
        $this->assertSame(0, $this->callCommand('myapes:authorization-sync'));
        $role = Role::query()->create([
            'name' => 'actorless-local-reviewer',
            'guard_name' => 'web',
        ]);
        DB::table('role_sources')->insert([
            'user_id' => $local->id,
            'role_id' => $role->id,
            'source' => RoleSource::SOURCE_LOCAL,
            'source_key' => RoleSource::SOURCE_LOCAL,
            'directory_group_id' => null,
            'granted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => 'user',
            'model_id' => $local->id,
        ]);

        $exitCode = $this->callCommand('myapes:authorization-check');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization check: failed (provenance_integrity)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_authorization_check_rejects_non_local_role_provenance_with_an_actor(): void
    {
        [, $superAdmin, $missing] = $this->activationUsers();
        $directory = $this->directory();
        $directory->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $directory->missingEmails = [$missing->email];
        $this->assertSame(0, $this->callCommand('myapes:authorization-sync'));
        DB::table('role_sources')
            ->where('source', RoleSource::SOURCE_DIRECTORY)
            ->where('user_id', $superAdmin->id)
            ->update(['granted_by' => $superAdmin->id]);

        $exitCode = $this->callCommand('myapes:authorization-check');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization check: failed (provenance_integrity)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_authorization_check_rejects_a_super_admin_when_the_required_catalogue_was_not_persisted(): void
    {
        [, $superAdmin, $missing] = $this->activationUsers();
        $directory = $this->directory();
        $directory->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $directory->missingEmails = [$missing->email];
        $this->assertSame(0, $this->callCommand('myapes:authorization-sync'));
        $this->assertSame(
            DirectoryGroup::STATUS_MISSING,
            DirectoryGroup::query()
                ->where('name', 'myapesaccount.superadmin')
                ->value('status'),
        );
        $this->app->detectEnvironment(fn (): string => 'production');

        $exitCode = $this->callCommand('myapes:authorization-check');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization check: failed (super_admin_unavailable)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_fresh_phase_b_upgrade_persists_the_required_catalogue_before_authorization_check(): void
    {
        [, $superAdmin, $missing] = $this->activationUsers();
        $this->runInMaintenanceMode(
            fn () => $this->phaseBMigration()->down(),
        );
        $this->phaseBMigration()->up();
        $directory = $this->directory();
        $directory->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $directory->missingEmails = [$missing->email];
        $this->assertSame(
            DirectoryGroup::STATUS_MISSING,
            DirectoryGroup::query()
                ->where('name', 'myapesaccount.superadmin')
                ->value('status'),
        );

        $this->assertSame(0, $this->callCommand('myapes:directory-sync'));
        $this->assertSame(
            DirectoryGroup::STATUS_PRESENT,
            DirectoryGroup::query()
                ->where('name', 'myapesaccount.superadmin')
                ->value('status'),
        );
        $this->assertSame(0, $this->callCommand('myapes:authorization-sync'));
        $this->app->detectEnvironment(fn (): string => 'production');

        $exitCode = $this->callCommand('myapes:authorization-check');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            'Effective super-admins: ok (1 users)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_failed_catalogue_sync_cannot_leave_a_role_only_super_admin_eligible_for_activation(): void
    {
        [, $superAdmin, $missing] = $this->activationUsers();
        $directory = $this->directory();
        $directory->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $directory->missingEmails = [$missing->email];
        $directory->enumerationFailure = new DirectoryUnavailable(
            'ldap-password-canary',
        );

        $syncExit = $this->callCommand('myapes:directory-sync');
        $syncOutput = Artisan::output();

        $this->assertSame(1, $syncExit);
        $this->assertStringContainsString(
            'Directory catalogue: failed (directory_unavailable)',
            $syncOutput,
        );
        $this->assertStringNotContainsString(
            'ldap-password-canary',
            $syncOutput,
        );
        $this->assertSame(
            DirectoryGroup::STATUS_MISSING,
            DirectoryGroup::query()
                ->where('name', 'myapesaccount.superadmin')
                ->value('status'),
        );
        $this->assertSame(0, $this->callCommand('myapes:authorization-sync'));
        $this->app->detectEnvironment(fn (): string => 'production');

        $checkExit = $this->callCommand('myapes:authorization-check');
        $checkOutput = Artisan::output();

        $this->assertSame(1, $checkExit);
        $this->assertStringContainsString(
            'Authorization check: failed (super_admin_unavailable)',
            $checkOutput,
        );
        $this->assertCanariesAreAbsent($checkOutput);
    }

    public function test_authorization_check_rejects_source_pivot_drift_and_wildcard_mappings(): void
    {
        [, $superAdmin, $missing] = $this->activationUsers();
        $directory = $this->directory();
        $directory->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $directory->missingEmails = [$missing->email];
        $this->assertSame(0, $this->callCommand('myapes:authorization-sync'));
        $local = User::query()
            ->where('identity_type', User::IDENTITY_LOCAL)
            ->firstOrFail();
        DB::table('role_sources')
            ->where('user_id', $local->id)
            ->delete();

        $driftExit = $this->callCommand('myapes:authorization-check');
        $driftOutput = Artisan::output();

        $this->assertSame(1, $driftExit);
        $this->assertStringContainsString(
            'Authorization check: failed (source_pivot_equality)',
            $driftOutput,
        );
        $this->assertCanariesAreAbsent($driftOutput);

        $this->assertSame(0, $this->callCommand('myapes:authorization-sync'));
        $wildcard = DirectoryGroup::query()->create([
            'name' => 'myapes.*',
            'status' => DirectoryGroup::STATUS_PRESENT,
        ]);
        DB::table('directory_group_role_mappings')->insert([
            'directory_group_id' => $wildcard->id,
            'role_id' => Role::query()->where('name', 'staff')->value('id'),
            'is_immutable' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mappingExit = $this->callCommand('myapes:authorization-check');
        $mappingOutput = Artisan::output();

        $this->assertSame(1, $mappingExit);
        $this->assertStringContainsString(
            'Authorization check: failed (mapping_integrity)',
            $mappingOutput,
        );
        $this->assertStringNotContainsString('myapes.*', $mappingOutput);
        $this->assertCanariesAreAbsent($mappingOutput);
    }

    public function test_authorization_check_rejects_a_missing_required_index(): void
    {
        [, $superAdmin, $missing] = $this->activationUsers();
        $directory = $this->directory();
        $directory->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $directory->missingEmails = [$missing->email];
        $this->assertSame(0, $this->callCommand('myapes:authorization-sync'));
        Schema::table('directory_sync_runs', function (Blueprint $table): void {
            $table->dropIndex(['source']);
        });

        $exitCode = $this->callCommand('myapes:authorization-check');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization check: failed (authorization_schema)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_authorization_check_rejects_a_missing_spatie_index(): void
    {
        [, $superAdmin, $missing] = $this->activationUsers();
        $directory = $this->directory();
        $directory->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $directory->missingEmails = [$missing->email];
        $this->assertSame(0, $this->callCommand('myapes:authorization-sync'));
        Schema::table('permissions', function (Blueprint $table): void {
            $table->dropUnique(['name', 'guard_name']);
        });

        $exitCode = $this->callCommand('myapes:authorization-check');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization check: failed (authorization_schema)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_authorization_check_rejects_a_mapping_to_a_non_web_role(): void
    {
        [, $superAdmin, $missing] = $this->activationUsers();
        $directory = $this->directory();
        $directory->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $directory->missingEmails = [$missing->email];
        $this->assertSame(0, $this->callCommand('myapes:authorization-sync'));
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'api-only-canary',
            'guard_name' => 'api',
            'is_protected' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $group = DirectoryGroup::query()->create([
            'name' => 'myapes.api-only-canary',
            'status' => DirectoryGroup::STATUS_PRESENT,
        ]);
        DB::table('directory_group_role_mappings')->insert([
            'directory_group_id' => $group->id,
            'role_id' => $roleId,
            'is_immutable' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = $this->callCommand('myapes:authorization-check');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization check: failed (mapping_integrity)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_authorization_check_rejects_extra_immutable_mapping_tuples(): void
    {
        [, $superAdmin, $missing] = $this->activationUsers();
        $directory = $this->directory();
        $directory->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $directory->missingEmails = [$missing->email];
        $this->assertSame(0, $this->callCommand('myapes:authorization-sync'));
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'case-reviewer-canary',
            'guard_name' => 'web',
            'is_protected' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $group = DirectoryGroup::query()->create([
            'name' => 'myapes.case-reviewer-canary',
            'status' => DirectoryGroup::STATUS_PRESENT,
        ]);
        DB::table('directory_group_role_mappings')->insert([
            'directory_group_id' => $group->id,
            'role_id' => $roleId,
            'is_immutable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = $this->callCommand('myapes:authorization-check');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization check: failed (mapping_integrity)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    public function test_authorization_check_rejects_directory_custom_provenance_without_protected_eligibility(): void
    {
        [, $superAdmin, $missing] = $this->activationUsers();
        $directory = $this->directory();
        $directory->groupsByEmail = [
            $superAdmin->email => ['myapesaccount.superadmin'],
        ];
        $directory->missingEmails = [$missing->email];
        $this->assertSame(0, $this->callCommand('myapes:authorization-sync'));
        $custom = Role::query()->create([
            'name' => 'case-reviewer',
            'guard_name' => 'web',
        ]);
        $group = DirectoryGroup::query()->create([
            'name' => 'myapes.case-reviewers',
            'status' => DirectoryGroup::STATUS_PRESENT,
        ]);
        DB::table('directory_group_role_mappings')->insert([
            'directory_group_id' => $group->id,
            'role_id' => $custom->id,
            'is_immutable' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_sources')->insert([
            'user_id' => $missing->id,
            'role_id' => $custom->id,
            'source' => RoleSource::SOURCE_DIRECTORY,
            'source_key' => 'directory:'.$group->id,
            'directory_group_id' => $group->id,
            'granted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $custom->id,
            'model_type' => 'user',
            'model_id' => $missing->id,
        ]);

        $exitCode = $this->callCommand('myapes:authorization-check');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Authorization check: failed (provenance_integrity)',
            $output,
        );
        $this->assertCanariesAreAbsent($output);
    }

    /**
     * @return array{User, User, User}
     */
    private function activationUsers(): array
    {
        $local = User::factory()
            ->accessLevel(User::ROLE_ADMIN)
            ->create([
                'email' => 'local-person-canary@example.test',
                'remember_token' => 'local-remember-canary',
            ]);
        $superAdmin = User::factory()
            ->accessLevel(User::ROLE_SUPERADMIN)
            ->cloudronIdentity('oidc-subject-canary-super')
            ->create([
                'email' => 'super-person-canary@example.test',
                'remember_token' => 'super-remember-canary',
            ]);
        $missing = User::factory()
            ->accessLevel(User::ROLE_STAFF)
            ->cloudronIdentity('oidc-subject-canary-missing')
            ->create([
                'email' => 'missing-person-canary@example.test',
                'remember_token' => 'missing-remember-canary',
            ]);

        return [
            $local->refresh(),
            $superAdmin->refresh(),
            $missing->refresh(),
        ];
    }

    private function tamperSynchronizableMetadata(): void
    {
        DB::table('role_has_permissions')
            ->where('role_id', Role::query()->where('name', 'staff')->value('id'))
            ->where('permission_id', DB::table('permissions')
                ->where('name', 'staff.access')
                ->value('id'))
            ->delete();
        DB::table('directory_group_role_mappings')
            ->where('directory_group_id', DirectoryGroup::query()
                ->where('name', 'myapesaccount.superadmin')
                ->value('id'))
            ->delete();
    }

    private function installDirectory(): void
    {
        $directory = new class extends LdapGroupResolver
        {
            /**
             * @var array<string, array<int, string>>
             */
            public array $groupsByEmail = [];

            /**
             * @var array<int, string>
             */
            public array $missingEmails = [];

            /**
             * @var array<string, Throwable>
             */
            public array $failuresByEmail = [];

            public ?Throwable $enumerationFailure = null;

            public int $enumerationCalls = 0;

            public ?\Closure $beforeResolutionReturn = null;

            /**
             * @return array<int, array{name: string, external_id: ?string, member_count: int}>
             */
            public function enumerateGroups(): array
            {
                $this->enumerationCalls++;

                if ($this->enumerationFailure !== null) {
                    throw $this->enumerationFailure;
                }

                return [
                    ['name' => 'myapesaccount.admin', 'external_id' => '4102', 'member_count' => 1],
                    ['name' => 'myapesaccount.staff', 'external_id' => '4101', 'member_count' => 1],
                    ['name' => 'myapesaccount.superadmin', 'external_id' => '4103', 'member_count' => 1],
                    ['name' => 'myapesaccount.volunteer', 'external_id' => '4104', 'member_count' => 0],
                    ['name' => 'myapesaccount.student', 'external_id' => '4105', 'member_count' => 0],
                ];
            }

            /**
             * @return array<int, string>
             */
            public function resolveByEmail(string $email): array
            {
                if (isset($this->failuresByEmail[$email])) {
                    throw $this->failuresByEmail[$email];
                }

                if (in_array($email, $this->missingEmails, true)) {
                    throw new DirectoryIdentityNotFound(
                        'directory-identity-canary',
                    );
                }

                if ($this->beforeResolutionReturn instanceof \Closure) {
                    $callback = $this->beforeResolutionReturn;
                    $this->beforeResolutionReturn = null;
                    $callback($email);
                }

                return DirectoryGroupPrefix::filterGroups(
                    $this->groupsByEmail[$email] ?? [],
                );
            }
        };
        $this->app->instance(LdapGroupResolver::class, $directory);
    }

    private function directory(): object
    {
        return app(LdapGroupResolver::class);
    }

    private function callCommand(string $command): int
    {
        return Artisan::call($command, [
            '--no-interaction' => true,
            '--no-ansi' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validMetadata(): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/auth',
            'token_endpoint' => self::ISSUER.'/token',
            'userinfo_endpoint' => self::ISSUER.'/me',
            'jwks_uri' => self::ISSUER.'/jwks',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['openid', 'profile', 'email'],
        ];
    }

    private function phaseBMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path(
            'migrations/2026_07_28_000100_cut_over_authorization_domain.php',
        );

        return $migration;
    }

    private function assertCanariesAreAbsent(string $output): void
    {
        foreach ([
            'oidc-client-id-canary',
            'oidc-client-secret-canary',
            'local-person-canary',
            'super-person-canary',
            'missing-person-canary',
            'oidc-subject-canary',
            'remember-canary',
            'ldap-password-canary',
            'identity-canary',
            'directory-identity-canary',
        ] as $canary) {
            $this->assertStringNotContainsString($canary, $output);
        }
    }

    private function downgradeAuthorizationGuardToPreviousGeneration(): void
    {
        $driver = DB::connection()->getDriverName();
        $legacyLevels = "'service_user', 'staff', 'admin', 'superadmin'";
        $currentLevels = "'service_user', 'student', 'volunteer', 'staff', 'admin', 'superadmin'";

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
}
