<?php

namespace Tests\Feature;

use App\Core\Accounts\DirectoryGroup;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Exceptions\AuthorizationLifecycleException;
use App\Services\AuthorizationPhaseBSchemaInspector;
use App\Services\AuthorizationProfile;
use App\Services\DirectoryRoleSynchronizer;
use App\Support\AuthorizationCompatibilityDatabaseGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class SuperAdminRetirementMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_11_02_000100_merge_super_admin_into_administrator';

    public function test_preflight_accepts_the_super_admin_guard_revision_only_while_the_merge_is_pending(): void
    {
        $this->seedProductionSuperAdmin();
        $inspector = app(AuthorizationPhaseBSchemaInspector::class);
        $guard = app(AuthorizationCompatibilityDatabaseGuard::class);

        $this->assertFalse($guard->isInstalled());
        $this->assertTrue($guard->isSuperAdminRevisionInstalled());

        try {
            $inspector->assertReady();
            $this->fail('Preflight accepted the super-admin guard revision after the merge migration ran.');
        } catch (AuthorizationLifecycleException $exception) {
            $this->assertSame('authorization_schema', $exception->check);
        }

        DB::table('migrations')->where('migration', self::MIGRATION)->delete();

        $inspector->assertReady();
    }

    public function test_merge_migration_folds_the_super_admin_role_group_and_permission_into_administrator(): void
    {
        $user = $this->seedProductionSuperAdmin();
        $epoch = (int) $user->fresh()->authorization_epoch;
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();

        $this->assertSame(0, Artisan::call('migrate', [
            '--path' => 'database/migrations/'.self::MIGRATION.'.php',
            '--force' => true,
        ]));

        $this->assertDatabaseMissing('roles', ['name' => 'super-admin']);
        $this->assertDatabaseMissing('permissions', ['name' => 'superadmin.access']);
        $this->assertDatabaseMissing('directory_groups', ['name' => 'myapesaccount.superadmin']);
        $this->assertTrue(app(AuthorizationCompatibilityDatabaseGuard::class)->isInstalled());

        $user = $user->fresh();
        $adminGroup = DirectoryGroup::query()->where('name', 'myapesaccount.admin')->firstOrFail();
        $administratorId = DB::table('roles')->where('name', AuthorizationProfile::ROLE_ADMINISTRATOR)->value('id');

        $this->assertSame(
            AuthorizationProfile::ROLE_ADMINISTRATOR,
            app(AuthorizationProfile::class)->effectiveProtectedRole($user),
        );
        $this->assertDatabaseHas('role_sources', [
            'user_id' => $user->id,
            'role_id' => $administratorId,
            'source' => RoleSource::SOURCE_DIRECTORY,
            'source_key' => 'directory:'.$adminGroup->id,
            'directory_group_id' => $adminGroup->id,
        ]);
        $this->assertSame('admin', $user->legacy_access_level);
        $this->assertSame(['myapesaccount.admin', 'myapesaccount.staff'], $user->ldap_groups);
        $this->assertSame($epoch + 1, (int) $user->authorization_epoch);
        $this->assertTrue(User::query()->eligibleAdministrators()->whereKey($user->id)->exists());
        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => $administratorId,
            'permission_id' => DB::table('permissions')->where('name', 'admin.modules.manage')->value('id'),
        ]);
        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => $administratorId,
            'permission_id' => DB::table('permissions')->where('name', 'admin.group-mappings.manage')->value('id'),
        ]);
    }

    public function test_directory_sync_grants_administrator_whether_the_superadmin_group_is_present_or_absent(): void
    {
        $synchronizer = app(DirectoryRoleSynchronizer::class);
        $profile = app(AuthorizationProfile::class);

        foreach ([
            'retired group only' => ['myapesaccount.superadmin'],
            'admin group only' => ['myapesaccount.admin'],
            'both groups' => ['myapesaccount.admin', 'myapesaccount.superadmin'],
        ] as $label => $groups) {
            $user = User::factory()
                ->accessLevel(User::ROLE_STAFF)
                ->cloudronIdentity('directory-'.str_replace(' ', '-', $label))
                ->create()
                ->refresh();

            $synchronizer->synchronize($user, $groups);

            $this->assertSame(
                AuthorizationProfile::ROLE_ADMINISTRATOR,
                $profile->effectiveProtectedRole($user->fresh()),
                "Directory sync with {$label} did not grant administrator.",
            );
        }

        $this->assertDatabaseMissing('directory_groups', ['name' => 'myapesaccount.superadmin']);
        $this->assertDatabaseMissing('roles', ['name' => 'super-admin']);
    }

    private function seedProductionSuperAdmin(): User
    {
        $user = User::factory()
            ->accessLevel(User::ROLE_STAFF)
            ->cloudronIdentity('retired-superadmin-subject')
            ->create(['ldap_groups' => ['myapesaccount.staff', 'myapesaccount.superadmin']])
            ->refresh();

        $guard = app(AuthorizationCompatibilityDatabaseGuard::class);
        $guard->drop();
        $now = now();

        $roleId = DB::table('roles')->insertGetId([
            'name' => 'super-admin',
            'guard_name' => 'web',
            'is_protected' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $permissionId = DB::table('permissions')->insertGetId([
            'name' => 'superadmin.access',
            'guard_name' => 'web',
            'is_code_owned' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('role_has_permissions')->insert([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);
        $groupId = DB::table('directory_groups')->insertGetId([
            'name' => 'myapesaccount.superadmin',
            'status' => DirectoryGroup::STATUS_PRESENT,
            'member_count' => 1,
            'app_enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('directory_group_role_mappings')->insert([
            'directory_group_id' => $groupId,
            'role_id' => $roleId,
            'is_immutable' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('role_sources')->insert([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'source' => RoleSource::SOURCE_DIRECTORY,
            'source_key' => 'directory:'.$groupId,
            'directory_group_id' => $groupId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->id,
        ]);
        DB::table('users')->where('id', $user->id)->update(['legacy_access_level' => 'superadmin']);
        DB::table('directory_groups')
            ->where('name', 'myapesaccount.admin')
            ->update(['status' => DirectoryGroup::STATUS_PRESENT, 'member_count' => 1]);

        if (DB::connection()->getDriverName() === 'mysql') {
            $definitions = new ReflectionMethod($guard, 'mysqlDefinitions');

            foreach ($definitions->invoke($guard, true) as $name => $definition) {
                DB::unprepared(
                    "CREATE TRIGGER {$name}
                     {$definition['timing']} {$definition['event']} ON {$definition['table']}
                     FOR EACH ROW
                     {$definition['body']}",
                );
            }

            return $user;
        }

        $definitions = new ReflectionMethod($guard, 'sqliteDefinitions');

        foreach ($definitions->invoke($guard, true) as $definition) {
            DB::unprepared($definition);
        }

        return $user;
    }
}
