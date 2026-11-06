<?php

namespace Tests\Feature;

use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesClinicalPersonas;
use Tests\TestCase;

class ClinicalConsultationPermissionMigrationTest extends TestCase
{
    use CreatesClinicalPersonas;
    use RefreshDatabase;

    private const CREATE = 'pet-care-clinic.consultations.create';

    private const CREATE_FOR_CLIENT = 'pet-care-clinic.consultations.create-for-client';

    private const UPDATE_OWN = 'pet-care-clinic.consultations.update-own';

    private const UPDATE_ALL = 'pet-care-clinic.consultations.update-all';

    public function test_upgrade_revokes_public_and_non_clinical_create_and_edit_grants(): void
    {
        app(ModuleInstallationSynchronizer::class)->synchronize();

        $this->grant(AuthorizationProfile::ROLE_SERVICE_USER, [self::CREATE, self::UPDATE_OWN]);
        $this->grant(AuthorizationProfile::ROLE_STAFF, [self::CREATE_FOR_CLIENT, self::UPDATE_OWN, self::UPDATE_ALL]);
        $this->grant(AuthorizationProfile::ROLE_VOLUNTEER, [self::CREATE_FOR_CLIENT, self::UPDATE_ALL]);

        $client = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $volunteer = User::factory()->protectedRole(AuthorizationProfile::ROLE_VOLUNTEER)->create();
        $this->assertTrue($this->holds($client, self::CREATE));
        $this->assertTrue($this->holds($staff, self::UPDATE_ALL));

        $migration = require database_path('migrations/2026_11_06_000200_restrict_consultation_editing_to_clinical_roles.php');
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([$client, $staff, $volunteer] as $user) {
            foreach ([self::CREATE, self::CREATE_FOR_CLIENT, self::UPDATE_OWN, self::UPDATE_ALL] as $permission) {
                $this->assertFalse($this->holds($user, $permission), "{$user->email} still holds {$permission}");
            }
        }

        $this->assertTrue($this->holds($client, 'pet-care-clinic.consultations.view-own'));
        foreach ([$staff, $volunteer] as $user) {
            $this->assertTrue($this->holds($user, 'pet-care-clinic.consultations.view-all'));
            $this->assertTrue($this->holds($user, 'pet-care-clinic.consultations.assign'));
            $this->assertTrue($this->holds($user, 'pet-care-clinic.consultations.close'));
        }

        $this->assertFalse((bool) Permission::query()->where('name', self::CREATE_FOR_CLIENT)->value('is_code_owned'));
        $this->assertFalse((bool) Permission::query()->where('name', self::UPDATE_OWN)->value('is_code_owned'));
    }

    public function test_vet_vet_nurse_and_administrator_hold_create_and_edit(): void
    {
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        foreach ([$this->vetStaff(), $this->vetNurseStaff(), $administrator] as $user) {
            $this->assertTrue($this->holds($user, self::CREATE), "{$user->email} cannot create");
            $this->assertTrue($this->holds($user, self::UPDATE_ALL), "{$user->email} cannot update");
        }
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function grant(string $roleName, array $permissions): void
    {
        $role = Role::query()->where('guard_name', 'web')->where('name', $roleName)->firstOrFail();

        foreach ($permissions as $name) {
            $permission = Permission::query()->where('guard_name', 'web')->where('name', $name)->first();

            if ($permission === null) {
                $permission = new Permission;
                $permission->forceFill(['name' => $name, 'guard_name' => 'web', 'is_code_owned' => true])->save();
            }

            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permission->id,
                'role_id' => $role->id,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function holds(User $user, string $permission): bool
    {
        return $user->fresh()->getAllPermissions()->pluck('name')->contains($permission);
    }
}
