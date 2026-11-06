<?php

namespace Tests\Feature\AccountSeparation;

use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StaffAbilityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_migration_removes_old_client_grants_from_staff_roles_and_keeps_work_abilities(): void
    {
        app(ModuleInstallationSynchronizer::class)->synchronize();
        $staffRole = Role::query()->where('guard_name', 'web')->where('name', AuthorizationProfile::ROLE_STAFF)->firstOrFail();

        foreach (['apes-cic.tickets.create', 'apes-cic.tickets.view-own', 'apes-cic.recruitment.view-own'] as $name) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => Permission::query()->where('guard_name', 'web')->where('name', $name)->value('id'),
                'role_id' => $staffRole->id,
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $this->assertTrue($this->holds($staff, 'apes-cic.tickets.create'));

        $migration = require database_path('migrations/2026_11_05_000100_separate_client_abilities_from_staff_roles.php');
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $staff = $staff->fresh();
        $this->assertFalse($this->holds($staff, 'apes-cic.tickets.create'));
        $this->assertFalse($this->holds($staff, 'apes-cic.tickets.view-own'));
        $this->assertFalse($this->holds($staff, 'apes-cic.recruitment.view-own'));
        $this->assertTrue($this->holds($staff, 'apes-cic.tickets.view-all'));
        $this->assertTrue($this->holds($staff, 'apes-cic.tickets.comment-own'));
        $this->assertTrue($this->holds($staff, 'shelter-rescue.cases.create-for-client'));
        $this->assertTrue($this->holds($staff, 'pet-care-clinic.consultations.view-all'));

        $client = User::factory()->create();
        $this->assertTrue($this->holds($client, 'apes-cic.tickets.create'));
        $this->assertTrue($this->holds($client, 'apes-cic.recruitment.view-own'));
        $this->assertFalse($this->holds($client, 'shelter-rescue.cases.create-for-client'));
    }

    private function holds(User $user, string $permission): bool
    {
        return $user->fresh()->getAllPermissions()->pluck('name')->contains($permission);
    }
}
