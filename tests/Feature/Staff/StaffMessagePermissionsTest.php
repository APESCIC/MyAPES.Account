<?php

namespace Tests\Feature\Staff;

use App\Core\Access\PermissionNaming;
use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\AuthorizationRoleMaterializer;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Staff email permissions (#500): staff.messages.send,
 * staff.messages.send.all and staff.messages.history.view.
 */
class StaffMessagePermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_send_and_history_are_granted_to_staff_volunteers_students_and_administrators(): void
    {
        foreach ([
            AuthorizationProfile::ROLE_STUDENT,
            AuthorizationProfile::ROLE_VOLUNTEER,
            AuthorizationProfile::ROLE_STAFF,
            AuthorizationProfile::ROLE_ADMINISTRATOR,
        ] as $role) {
            $user = User::factory()->protectedRole($role)->create();
            $this->actingAs($user);

            $this->assertTrue(
                Gate::forUser($user)->allows(AuthorizationProfile::PERMISSION_MESSAGES_SEND),
                "[{$role}] should hold staff.messages.send.",
            );
            $this->assertTrue(
                Gate::forUser($user)->allows(AuthorizationProfile::PERMISSION_MESSAGES_HISTORY_VIEW),
                "[{$role}] should hold staff.messages.history.view.",
            );
        }

        $serviceUser = User::factory()->create();
        $this->actingAs($serviceUser);

        $this->assertFalse(
            Gate::forUser($serviceUser)->allows(AuthorizationProfile::PERMISSION_MESSAGES_SEND),
        );
        $this->assertFalse(
            Gate::forUser($serviceUser)->allows(AuthorizationProfile::PERMISSION_MESSAGES_HISTORY_VIEW),
        );
    }

    public function test_send_all_is_granted_to_administrators_by_default(): void
    {
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $this->actingAs($administrator);

        $this->assertTrue(
            Gate::forUser($administrator)->allows(AuthorizationProfile::PERMISSION_MESSAGES_SEND_ALL),
        );

        foreach ([
            AuthorizationProfile::ROLE_STAFF,
            AuthorizationProfile::ROLE_VOLUNTEER,
            AuthorizationProfile::ROLE_STUDENT,
        ] as $role) {
            $user = User::factory()->protectedRole($role)->create();
            $this->actingAs($user);

            $this->assertFalse(
                Gate::forUser($user)->allows(AuthorizationProfile::PERMISSION_MESSAGES_SEND_ALL),
                "[{$role}] must not hold staff.messages.send.all by default.",
            );
        }
    }

    public function test_send_all_can_be_granted_to_staff(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $role = Role::query()->create([
            'name' => 'staff-messages-send-all',
            'guard_name' => 'web',
            'is_protected' => false,
        ]);
        $role->permissions()->attach(
            Permission::query()
                ->where('name', AuthorizationProfile::PERMISSION_MESSAGES_SEND_ALL)
                ->where('guard_name', 'web')
                ->firstOrFail()
                ->id,
        );
        app(AuthorizationRoleMaterializer::class)->grant(
            $staff,
            $role,
            RoleSource::SOURCE_LOCAL,
            actor: $staff,
        );
        $this->actingAs($staff);

        $this->assertTrue(
            Gate::forUser($staff)->allows(AuthorizationProfile::PERMISSION_MESSAGES_SEND_ALL),
        );
    }

    public function test_permissions_are_seeded_as_code_owned_and_match_naming_convention(): void
    {
        $expected = [
            'staff.messages.send' => 4,
            'staff.messages.send.all' => 1,
            'staff.messages.history.view' => 4,
        ];

        foreach ($expected as $name => $expectedRoles) {
            $this->assertTrue(
                PermissionNaming::matchesConvention($name),
                "[{$name}] must match PermissionNaming.",
            );

            $permissionId = app('db')->table('permissions')
                ->where('guard_name', 'web')
                ->where('name', $name)
                ->where('is_code_owned', true)
                ->value('id');

            $this->assertNotNull($permissionId, "[{$name}] should be a seeded code-owned permission.");

            $linkedRoles = app('db')->table('role_has_permissions')
                ->where('permission_id', $permissionId)
                ->count();

            $this->assertSame($expectedRoles, $linkedRoles, "[{$name}] should be linked to {$expectedRoles} roles.");
        }
    }
}
