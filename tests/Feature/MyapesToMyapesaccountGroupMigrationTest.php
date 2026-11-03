<?php

namespace Tests\Feature;

use App\Core\Accounts\DirectoryGroup;
use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MyapesToMyapesaccountGroupMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_merges_legacy_groups_when_canonical_names_already_exist(): void
    {
        $administratorRole = Role::query()
            ->where('name', AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->firstOrFail();

        $canonicalAdmin = DirectoryGroup::query()
            ->where('name', 'myapesaccount.admin')
            ->firstOrFail();
        $legacyAdmin = DirectoryGroup::query()->create([
            'name' => 'myapes.admin',
            'status' => DirectoryGroup::STATUS_PRESENT,
            'app_enabled' => true,
        ]);
        $legacyStaff = DirectoryGroup::query()->create([
            'name' => 'myapes.staff',
            'status' => DirectoryGroup::STATUS_PRESENT,
            'app_enabled' => true,
        ]);

        DB::table('directory_group_role_mappings')->insert([
            'directory_group_id' => $legacyAdmin->id,
            'role_id' => $administratorRole->id,
            'is_immutable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create([
                'ldap_groups' => [
                    'myapes.admin',
                    'myapes.staff',
                ],
            ]);

        DB::table('role_sources')->insert([
            'user_id' => $user->id,
            'role_id' => $administratorRole->id,
            'source' => RoleSource::SOURCE_DIRECTORY,
            'source_key' => 'directory:'.$legacyAdmin->id,
            'directory_group_id' => $legacyAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('migrations')
            ->where(
                'migration',
                '2026_08_24_000002_migrate_myapes_to_myapesaccount_groups',
            )
            ->delete();

        $this->assertSame(
            0,
            Artisan::call('migrate', [
                '--path' => 'database/migrations/2026_08_24_000002_migrate_myapes_to_myapesaccount_groups.php',
                '--force' => true,
            ]),
        );

        $this->assertDatabaseMissing('directory_groups', [
            'name' => 'myapes.admin',
        ]);
        $this->assertDatabaseMissing('directory_groups', [
            'name' => 'myapes.staff',
        ]);
        $this->assertDatabaseHas('directory_groups', [
            'id' => $canonicalAdmin->id,
            'name' => 'myapesaccount.admin',
        ]);
        $this->assertDatabaseHas('directory_groups', [
            'name' => 'myapesaccount.staff',
        ]);

        $this->assertDatabaseMissing('directory_group_role_mappings', [
            'directory_group_id' => $legacyAdmin->id,
        ]);
        $this->assertDatabaseHas('role_sources', [
            'user_id' => $user->id,
            'role_id' => $administratorRole->id,
            'directory_group_id' => $canonicalAdmin->id,
            'source_key' => 'directory:'.$canonicalAdmin->id,
        ]);

        $user->refresh();
        $this->assertSame(
            ['myapesaccount.admin', 'myapesaccount.staff'],
            $user->ldap_groups,
        );

        $this->assertDatabaseMissing('directory_groups', [
            'id' => $legacyAdmin->id,
        ]);
        $this->assertDatabaseMissing('directory_groups', [
            'id' => $legacyStaff->id,
        ]);
    }
}
