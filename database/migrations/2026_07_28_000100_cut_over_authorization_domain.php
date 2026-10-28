<?php

use App\Core\Accounts\User;
use App\Services\AuthorizationPermissionSynchronizer;
use App\Services\AuthorizationPhaseBSchemaInspector;
use App\Support\AccessCompatibilityDatabaseGuard;
use App\Support\AuthorizationCompatibilityDatabaseGuard;
use App\Support\AuthorizationCutoverSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_ROLE_MAP = [
        'service_user' => 'service-user',
        'staff' => 'staff',
        'admin' => 'administrator',
        'superadmin' => 'super-admin',
    ];

    private const PROTECTED_ROLES = [
        'service-user',
        'student',
        'volunteer',
        'staff',
        'administrator',
        'super-admin',
    ];

    private const PERMISSIONS = [
        'student.access',
        'volunteer.access',
        'staff.access',
        'admin.access',
        'superadmin.access',
        'admin.users.view',
        'admin.users.manage',
        'admin.groups.view',
        'admin.group-mappings.manage',
        'admin.roles.view',
        'admin.roles.manage',
        'admin.permissions.view',
        'admin.modules.view',
        'admin.modules.manage',
        'admin.analytics.view',
        'admin.maintenance.manage',
        'admin.emails.view',
        'admin.emails.manage',
    ];

    private const DIRECTORY_MAPPINGS = [
        'myapesaccount.staff' => 'staff',
        'myapesaccount.admin' => 'administrator',
        'myapesaccount.superadmin' => 'super-admin',
        'myapesaccount.volunteer' => 'volunteer',
        'myapesaccount.student' => 'student',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            $guard = app(AuthorizationCompatibilityDatabaseGuard::class);

            if (Schema::hasTable('authorization_states') && $guard->isInstalled()) {
                if (! DB::table('authorization_states')
                    ->where('id', 1)
                    ->whereNotNull('cutover_completed_at')
                    ->exists()) {
                    throw new RuntimeException(
                        'Authorization cutover completion marker verification failed.',
                    );
                }

                app(AuthorizationPhaseBSchemaInspector::class)->assertReady();
                DB::transaction(function () use ($guard): void {
                    $this->lockUsersForCutover();
                    $guard->reconcileLegacySources();
                    $this->verifyParity($guard);
                });

                return;
            }

            throw new RuntimeException(
                'Authorization cutover is incomplete and cannot be retried safely.',
            );
        }

        $phaseAGuard = app(AccessCompatibilityDatabaseGuard::class);
        $guard = app(AuthorizationCompatibilityDatabaseGuard::class);

        if (! $phaseAGuard->isInstalled()) {
            if (! $this->isRetryAfterPhaseAGuardDrop($guard)) {
                throw new RuntimeException(
                    'Phase A compatibility guard is required for authorization cutover.',
                );
            }

            app(AuthorizationPhaseBSchemaInspector::class)
                ->assertCutoverRetryReady();
            $this->assertLegacyAccessLevelsAreValid();
            $this->assertPhaseARoleMirrorParity();
            DB::transaction(function () use ($guard): void {
                $this->lockUsersForCutover();
                $guard->reconcileLegacySources();
                $this->verifyParity($guard);
            });
            $phaseAGuard->install();

            if (! $phaseAGuard->isInstalled()) {
                throw new RuntimeException(
                    'Phase A compatibility guard recovery failed during authorization cutover retry.',
                );
            }
        }

        $this->assertLegacyAccessLevelsAreValid();
        $this->assertPhaseARoleMirrorParity();

        try {
            $this->addUserAuthorizationFields();
            $this->createAuthorizationTables();
            $this->seedAuthorizationMetadata();

            $guard->install();
            DB::transaction(function () use ($guard): void {
                $this->lockUsersForCutover();
                $guard->reconcileLegacySources();
                $this->verifyParity($guard);

                DB::table('authorization_states')
                    ->where('id', 1)
                    ->update([
                        'cutover_completed_at' => now(),
                        'updated_at' => now(),
                    ]);
            });
        } catch (Throwable $exception) {
            $this->cleanPartialInstallation($guard);

            throw $exception;
        }

        try {
            $phaseAGuard->drop();
        } catch (Throwable $exception) {
            $phaseAGuard->install();

            throw $exception;
        }

        try {
            app(AuthorizationCutoverSchema::class)->dropLegacyRoleColumn();
        } catch (Throwable $exception) {
            $phaseAGuard->install();

            throw $exception;
        }
    }

    private function isRetryAfterPhaseAGuardDrop(
        AuthorizationCompatibilityDatabaseGuard $guard,
    ): bool {
        return Schema::hasTable('authorization_states')
            && $guard->isInstalled()
            && DB::table('authorization_states')
                ->where('id', 1)
                ->whereNotNull('cutover_completed_at')
                ->exists();
    }

    public function down(): void
    {
        $this->assertMaintenanceModeActive();
        $this->assertMaintenanceDowngradeIsRepresentable();
        $this->invalidateSessionsBeforeDowngrade();

        if (! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('role', 32)->default('service_user');
            });
        }

        DB::table('users')->update([
            'role' => DB::raw('legacy_access_level'),
        ]);

        $phaseAGuard = app(AccessCompatibilityDatabaseGuard::class);
        $phaseAGuard->install();

        if (! $phaseAGuard->isInstalled()) {
            throw new RuntimeException(
                'Phase A compatibility guard verification failed during rollback.',
            );
        }

        app(AuthorizationCompatibilityDatabaseGuard::class)->drop();
        $this->dropAuthorizationTables();
        $this->removeUserAuthorizationFields();
        $this->removeAuthorizationMetadata();

        // SQLite rebuilds the users table while dropping the Phase B fields,
        // which also removes its triggers. Reinstall and verify the rollback
        // guard so the completed maintenance rollback always retains Phase A.
        $phaseAGuard->install();

        if (! $phaseAGuard->isInstalled()) {
            throw new RuntimeException(
                'Phase A compatibility guard verification failed after rollback cleanup.',
            );
        }
    }

    private function assertMaintenanceModeActive(): void
    {
        if (! app()->isDownForMaintenance()) {
            throw new RuntimeException(
                'Maintenance downgrade requires Laravel maintenance mode.',
            );
        }
    }

    private function assertMaintenanceDowngradeIsRepresentable(): void
    {
        if (Schema::hasColumn('users', 'suspended_at')
            && DB::table('users')
                ->whereNotNull('suspended_at')
                ->exists()) {
            throw new RuntimeException(
                'Maintenance downgrade cannot represent suspended accounts.',
            );
        }

        if (Schema::hasColumn('users', 'authorization_epoch')
            && DB::table('users')
                ->where('authorization_epoch', '<>', 1)
                ->exists()) {
            throw new RuntimeException(
                'Maintenance downgrade cannot represent changed authorization epochs.',
            );
        }

        if (Schema::hasTable('permission_sources')
            && DB::table('permission_sources')->exists()) {
            throw new RuntimeException(
                'Maintenance downgrade cannot represent direct user permissions.',
            );
        }

        if (Schema::hasTable('authorization_states')
            && DB::table('authorization_states')
                ->where('id', 1)
                ->where('authorization_epoch', '<>', 1)
                ->exists()) {
            throw new RuntimeException(
                'Maintenance downgrade cannot represent changed authorization state.',
            );
        }
    }

    private function invalidateSessionsBeforeDowngrade(): void
    {
        $driver = (string) config('session.driver');

        if ($driver === 'database') {
            if (Schema::hasTable((string) config('session.table', 'sessions'))) {
                DB::table((string) config('session.table', 'sessions'))
                    ->delete();
            }

            DB::table('users')->update(['remember_token' => null]);

            return;
        }

        if ($driver === 'file') {
            $sessionPath = (string) config(
                'session.files',
                storage_path('framework/sessions'),
            );

            if (is_dir($sessionPath)) {
                foreach (File::files($sessionPath) as $sessionFile) {
                    if ($sessionFile->getFilename() !== '.gitignore'
                        && ! File::delete($sessionFile->getPathname())) {
                        throw new RuntimeException(
                            'Maintenance downgrade could not invalidate file sessions.',
                        );
                    }
                }
            }

            DB::table('users')->update(['remember_token' => null]);

            return;
        }

        if ($driver === 'array') {
            DB::table('users')->update(['remember_token' => null]);

            return;
        }

        if ($driver === 'redis') {
            $handler = app('session')->driver()->getHandler();

            if (! $handler instanceof CacheBasedSessionHandler
                || ! $handler->getCache()->flush()) {
                throw new RuntimeException(
                    'Maintenance downgrade could not invalidate Redis sessions.',
                );
            }

            DB::table('users')->update(['remember_token' => null]);

            return;
        }

        throw new RuntimeException(
            'Maintenance downgrade cannot safely invalidate the configured sessions.',
        );
    }

    private function assertLegacyAccessLevelsAreValid(): void
    {
        if (! Schema::hasColumn('users', 'legacy_access_level')) {
            throw new RuntimeException(
                'Legacy access mirror is required for authorization cutover.',
            );
        }

        if (DB::table('users')
            ->where(static function ($query): void {
                $query
                    ->whereNull('legacy_access_level')
                    ->orWhereRaw("TRIM(legacy_access_level) = ''")
                    ->orWhereNotIn(
                        'legacy_access_level',
                        array_keys(self::LEGACY_ROLE_MAP),
                    );
            })
            ->exists()) {
            throw new RuntimeException(
                'Cannot cut over authorization while users contain unsupported legacy access levels.',
            );
        }
    }

    private function assertPhaseARoleMirrorParity(): void
    {
        if (DB::table('users')
            ->where(static function ($query): void {
                $query
                    ->whereNull('role')
                    ->orWhereNull('legacy_access_level')
                    ->orWhereColumn('role', '<>', 'legacy_access_level');
            })
            ->exists()) {
            throw new RuntimeException(
                'Phase A role mirror parity verification failed.',
            );
        }
    }

    private function addUserAuthorizationFields(): void
    {
        if (Schema::hasColumn('users', 'authorization_epoch')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('authorization_epoch')->default(1);
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('suspension_reason', 500)->nullable();
        });
    }

    private function createAuthorizationTables(): void
    {
        if (! Schema::hasTable('authorization_states')) {
            Schema::create('authorization_states', function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->unsignedBigInteger('authorization_epoch')->default(1);
                $table->timestamp('cutover_completed_at')->nullable();
                $table->timestamp('session_cutover_completed_at')->nullable();
                $table->string('directory_sync_owner_token', 64)->nullable();
                $table->timestamp('directory_sync_expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('directory_groups')) {
            Schema::create('directory_groups', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 191)->unique();
                $table->string('external_id', 191)->nullable()->unique();
                $table->unsignedBigInteger('member_count')->nullable();
                $table->string('status', 32)->default('missing')->index();
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('directory_sync_runs')) {
            Schema::create('directory_sync_runs', function (Blueprint $table): void {
                $table->id();
                $table->enum('source', ['manual', 'scheduled'])->index();
                $table->enum('status', ['running', 'succeeded', 'failed'])->index();
                $table->uuid('queue_job_uuid')->nullable();
                $table->unsignedSmallInteger('queue_attempt')->nullable();
                $table->string('lease_owner_token', 64)->nullable();
                $table->timestamp('started_at');
                $table->timestamp('finished_at')->nullable();
                $table->unsignedBigInteger('groups_seen')->default(0);
                $table->unsignedBigInteger('groups_missing')->default(0);
                $table->string('error_code', 64)->nullable();
                $table->timestamps();
                $table->unique(['queue_job_uuid', 'queue_attempt']);
            });
        }

        if (! Schema::hasTable('directory_group_role_mappings')) {
            Schema::create('directory_group_role_mappings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('directory_group_id')
                    ->constrained('directory_groups')
                    ->cascadeOnDelete();
                $table->foreignId('role_id')
                    ->constrained('roles')
                    ->cascadeOnDelete();
                $table->boolean('is_immutable')->default(false);
                $table->timestamps();
                $table->unique(['directory_group_id', 'role_id']);
            });
        }

        if (! Schema::hasTable('role_sources')) {
            Schema::create('role_sources', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();
                $table->foreignId('role_id')
                    ->constrained('roles')
                    ->cascadeOnDelete();
                $table->enum('source', [
                    'system',
                    'directory',
                    'local',
                    'legacy-compatibility',
                ])->index();
                $table->string('source_key', 191);
                $table->foreignId('directory_group_id')
                    ->nullable()
                    ->constrained('directory_groups')
                    ->cascadeOnDelete();
                $table->foreignId('granted_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamps();
                $table->unique(['user_id', 'role_id', 'source_key']);
            });
        }

        if (! Schema::hasTable('permission_sources')) {
            Schema::create('permission_sources', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();
                $table->foreignId('permission_id')
                    ->constrained('permissions')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->enum('source', ['system', 'local'])->index();
                $table->string('source_key', 191);
                $table->foreignId('granted_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamps();
                $table->unique([
                    'user_id',
                    'permission_id',
                    'source_key',
                ]);
            });
        }
    }

    private function seedAuthorizationMetadata(): void
    {
        $now = now();

        DB::table('authorization_states')->insertOrIgnore([
            'id' => 1,
            'authorization_epoch' => 1,
            'cutover_completed_at' => null,
            'session_cutover_completed_at' => null,
            'directory_sync_owner_token' => null,
            'directory_sync_expires_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('roles')->insertOrIgnore(
            array_map(
                static fn (string $role): array => [
                    'name' => $role,
                    'guard_name' => 'web',
                    'is_protected' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                self::PROTECTED_ROLES,
            ),
        );
        $this->assertProtectedRolesAreExact();

        DB::table('permissions')->upsert(
            array_map(
                static fn (string $permission): array => [
                    'name' => $permission,
                    'guard_name' => 'web',
                    'is_code_owned' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                self::PERMISSIONS,
            ),
            ['name', 'guard_name'],
            ['is_code_owned', 'updated_at'],
        );
        app(AuthorizationPermissionSynchronizer::class)->synchronize();

        DB::table('directory_groups')->insertOrIgnore(
            array_map(
                static fn (string $group): array => [
                    'name' => $group,
                    'external_id' => null,
                    'member_count' => null,
                    'status' => 'missing',
                    'first_seen_at' => null,
                    'last_seen_at' => null,
                    'last_synced_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                array_keys(self::DIRECTORY_MAPPINGS),
            ),
        );

        foreach (self::DIRECTORY_MAPPINGS as $groupName => $roleName) {
            DB::table('directory_group_role_mappings')->updateOrInsert(
                [
                    'directory_group_id' => DB::table('directory_groups')
                        ->where('name', $groupName)
                        ->value('id'),
                    'role_id' => DB::table('roles')
                        ->where('guard_name', 'web')
                        ->where('name', $roleName)
                        ->value('id'),
                ],
                [
                    'is_immutable' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    private function assertProtectedRolesAreExact(): void
    {
        $roles = DB::table('roles')
            ->where('guard_name', 'web')
            ->whereIn('name', self::PROTECTED_ROLES)
            ->get(['name', 'is_protected'])
            ->keyBy('name');

        foreach (self::PROTECTED_ROLES as $roleName) {
            $role = $roles->get($roleName);

            if ($role === null || (int) $role->is_protected !== 1) {
                throw new RuntimeException(
                    'Protected authorization role metadata verification failed.',
                );
            }
        }
    }

    private function verifyParity(
        AuthorizationCompatibilityDatabaseGuard $guard,
    ): void {
        if (! $guard->isInstalled()) {
            throw new RuntimeException(
                'Authorization compatibility database guard verification failed.',
            );
        }

        $roleIds = DB::table('roles')
            ->where('guard_name', 'web')
            ->whereIn('name', self::PROTECTED_ROLES)
            ->pluck('id', 'name');
        $protectedRoleIds = $roleIds
            ->values()
            ->map(static fn (mixed $roleId): int => (int) $roleId)
            ->sort()
            ->values()
            ->all();

        DB::table('users')
            ->orderBy('id')
            ->select(['id', 'legacy_access_level'])
            ->each(function (object $user) use (
                $roleIds,
                $protectedRoleIds,
            ): void {
                $roleName = self::LEGACY_ROLE_MAP[$user->legacy_access_level] ?? null;
                $roleId = $roleName === null ? null : $roleIds->get($roleName);
                $expectedRoleIds = $roleId === null ? [] : [(int) $roleId];
                $canonicalSourceRoleIds = DB::table('role_sources')
                    ->where('user_id', $user->id)
                    ->where('source', 'legacy-compatibility')
                    ->where('source_key', 'legacy-compatibility')
                    ->whereIn('role_id', $protectedRoleIds)
                    ->whereNull('directory_group_id')
                    ->whereNull('granted_by')
                    ->orderBy('role_id')
                    ->pluck('role_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();
                $protectedPivotRoleIds = DB::table('model_has_roles')
                    ->where('model_type', (new User)->getMorphClass())
                    ->where('model_id', $user->id)
                    ->whereIn('role_id', $protectedRoleIds)
                    ->orderBy('role_id')
                    ->pluck('role_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();

                if ($roleId === null
                    || $canonicalSourceRoleIds !== $expectedRoleIds
                    || $protectedPivotRoleIds !== $expectedRoleIds) {
                    throw new RuntimeException(
                        'Legacy authorization backfill parity verification failed.',
                    );
                }
            });

        $sourcePairs = DB::table('role_sources')
            ->get(['user_id', 'role_id'])
            ->map(
                static fn (object $source): string => $source->user_id
                    .':'.$source->role_id,
            )
            ->unique()
            ->sort()
            ->values()
            ->all();
        $pivotPairs = DB::table('model_has_roles')
            ->where('model_type', (new User)->getMorphClass())
            ->get(['model_id', 'role_id'])
            ->map(
                static fn (object $pivot): string => $pivot->model_id
                    .':'.$pivot->role_id,
            )
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($sourcePairs !== $pivotPairs) {
            throw new RuntimeException(
                'Legacy authorization backfill parity verification failed.',
            );
        }
    }

    private function lockUsersForCutover(): void
    {
        DB::table('users')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    private function cleanPartialInstallation(
        AuthorizationCompatibilityDatabaseGuard $guard,
    ): void {
        try {
            $guard->drop();
        } catch (Throwable) {
            // Keep cleaning the remaining independently recoverable schema.
        }

        try {
            $this->dropAuthorizationTables();
        } catch (Throwable) {
            // Keep cleaning the remaining independently recoverable schema.
        }

        try {
            $this->removeUserAuthorizationFields();
        } catch (Throwable) {
            // Preserve the original migration failure.
        }

        try {
            $this->removeAuthorizationMetadata();
        } catch (Throwable) {
            // Preserve the original migration failure.
        }

        $phaseAGuard = app(AccessCompatibilityDatabaseGuard::class);
        $phaseAGuard->install();

        if (! $phaseAGuard->isInstalled()) {
            throw new RuntimeException(
                'Phase A compatibility guard recovery failed after cutover cleanup.',
            );
        }
    }

    private function dropAuthorizationTables(): void
    {
        Schema::dropIfExists('permission_sources');
        Schema::dropIfExists('role_sources');
        Schema::dropIfExists('directory_group_role_mappings');
        Schema::dropIfExists('directory_sync_runs');
        Schema::dropIfExists('directory_groups');
        Schema::dropIfExists('authorization_states');
    }

    private function removeUserAuthorizationFields(): void
    {
        if (! Schema::hasColumn('users', 'authorization_epoch')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['suspended_by']);
            $table->dropColumn([
                'authorization_epoch',
                'suspended_at',
                'suspended_by',
                'suspension_reason',
            ]);
        });
    }

    private function removeAuthorizationMetadata(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('roles')
            ->where('guard_name', 'web')
            ->whereIn('name', self::PROTECTED_ROLES)
            ->where('is_protected', true)
            ->delete();
        DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', self::PERMISSIONS)
            ->where('is_code_owned', true)
            ->delete();
    }
};
