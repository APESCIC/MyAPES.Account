<?php

namespace Tests\Feature;

use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\AuthorizationRoleMaterializer;
use App\Services\ModuleInstallationSynchronizer;
use App\Services\SessionAuthorizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ModulePermissionGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_public_module_permissions_work_in_password_context_without_opening_staff_abilities(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(
            app(SessionAuthorizationContext::class)->valuesFor(
                $user,
                SessionAuthorizationContext::METHOD_PASSWORD,
            ),
        );

        $this->assertTrue($user->can('apes-cic.tickets.create'));
        $this->assertTrue($user->can('apes-cic.tickets.view-own'));
        $this->assertFalse($user->can('apes-cic.tickets.assign'));
        $this->assertFalse($user->can('shelter-rescue.cases.view-all'));
    }

    public function test_directory_restricted_module_permissions_follow_protected_staff_eligibility(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $this->actingAs($staff);

        $this->assertTrue($staff->can('apes-cic.tickets.assign'));
        $this->assertTrue(
            $staff->can('pet-care-clinic.consultations.close'),
        );

        $serviceUser = User::factory()->create();
        $customRole = Role::query()->create([
            'name' => 'module-assignment-helper',
            'guard_name' => 'web',
            'is_protected' => false,
        ]);
        $customRole->permissions()->attach(
            Permission::query()
                ->where('name', 'apes-cic.tickets.assign')
                ->value('id'),
        );
        app(AuthorizationRoleMaterializer::class)->grant(
            $serviceUser,
            $customRole,
            RoleSource::SOURCE_LOCAL,
            actor: $staff,
        );
        $this->actingAs($serviceUser);

        $this->assertFalse($serviceUser->can('apes-cic.tickets.assign'));
    }

    public function test_module_administration_is_administrator_only(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $this->actingAs($staff);

        $this->assertFalse($staff->can('admin.modules.view'));
        $this->assertFalse($staff->can('admin.modules.manage'));

        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $this->actingAs($administrator);

        $this->assertTrue($administrator->can('admin.modules.view'));
        $this->assertTrue($administrator->can('admin.modules.manage'));
        $this->assertFalse($administrator->can('superadmin.access'));
    }

    public function test_suspension_denies_public_and_staff_module_permissions_immediately(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create([
                'suspended_at' => now(),
                'suspension_reason' => 'policy',
            ]);
        $this->actingAs($staff);

        $this->assertFalse($staff->can('apes-cic.tickets.create'));
        $this->assertFalse($staff->can('apes-cic.tickets.assign'));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function moduleDeleteAbilityProvider(): array
    {
        return [
            'student denies delete' => [AuthorizationProfile::ROLE_STUDENT, false],
            'volunteer denies delete' => [AuthorizationProfile::ROLE_VOLUNTEER, false],
            'staff allows delete' => [AuthorizationProfile::ROLE_STAFF, true],
            'administrator allows delete' => [AuthorizationProfile::ROLE_ADMINISTRATOR, true],
            'super-admin allows delete' => [AuthorizationProfile::ROLE_ADMINISTRATOR, true],
        ];
    }

    #[DataProvider('moduleDeleteAbilityProvider')]
    public function test_volunteer_and_student_lack_module_delete_abilities(
        string $protectedRole,
        bool $canDelete,
    ): void {
        $user = User::factory()
            ->protectedRole($protectedRole)
            ->create();
        $this->actingAs($user);

        $this->assertTrue($user->can('apes-cic.tickets.view-all'));
        $this->assertTrue($user->can('apes-cic.cases.view-all'));
        $this->assertSame($canDelete, $user->can('apes-cic.tickets.delete'));
        $this->assertSame($canDelete, $user->can('apes-cic.cases.delete'));
        $this->assertSame($canDelete, $user->can('shelter-rescue.tickets.delete'));
        $this->assertSame($canDelete, $user->can('shelter-rescue.cases.delete'));
        $this->assertSame($canDelete, $user->can('pet-care-clinic.tickets.delete'));
    }
}
