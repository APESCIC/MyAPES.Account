<?php

namespace Tests\Feature\AccountSeparation;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Client account management permissions (#613): staff.clients.search,
 * staff.clients.manage and staff.clients.sensitive, mapped to protected
 * roles, capability packs and default job roles.
 */
class ClientManagementPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_client_search_is_granted_to_staff_volunteers_students_and_administrators(): void
    {
        foreach ([AuthorizationProfile::ROLE_STUDENT, AuthorizationProfile::ROLE_VOLUNTEER, AuthorizationProfile::ROLE_STAFF, AuthorizationProfile::ROLE_ADMINISTRATOR] as $role) {
            $user = User::factory()->protectedRole($role)->create();
            $this->actingAs($user);

            $this->assertTrue(
                Gate::forUser($user)->allows(
                    AuthorizationProfile::PERMISSION_CLIENT_SEARCH,
                ),
                "[{$role}] should hold staff.clients.search.",
            );
        }

        $serviceUser = User::factory()->create();
        $this->actingAs($serviceUser);

        $this->assertFalse(
            Gate::forUser($serviceUser)->allows(
                AuthorizationProfile::PERMISSION_CLIENT_SEARCH,
            ),
        );
    }

    public function test_client_manage_is_granted_to_staff_and_administrators_only(): void
    {
        foreach ([AuthorizationProfile::ROLE_STAFF, AuthorizationProfile::ROLE_ADMINISTRATOR] as $role) {
            $user = User::factory()->protectedRole($role)->create();
            $this->actingAs($user);

            $this->assertTrue(
                Gate::forUser($user)->allows(
                    AuthorizationProfile::PERMISSION_CLIENT_MANAGE,
                ),
                "[{$role}] should hold staff.clients.manage.",
            );
        }

        foreach ([AuthorizationProfile::ROLE_STUDENT, AuthorizationProfile::ROLE_VOLUNTEER] as $role) {
            $user = User::factory()->protectedRole($role)->create();
            $this->actingAs($user);

            $this->assertFalse(
                Gate::forUser($user)->allows(
                    AuthorizationProfile::PERMISSION_CLIENT_MANAGE,
                ),
                "[{$role}] must not hold staff.clients.manage.",
            );
        }
    }

    public function test_client_sensitive_is_granted_to_administrators_only(): void
    {
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $this->actingAs($administrator);

        $this->assertTrue(
            Gate::forUser($administrator)->allows(
                AuthorizationProfile::PERMISSION_CLIENT_SENSITIVE,
            ),
        );

        foreach ([AuthorizationProfile::ROLE_STAFF, AuthorizationProfile::ROLE_VOLUNTEER, AuthorizationProfile::ROLE_STUDENT] as $role) {
            $user = User::factory()->protectedRole($role)->create();
            $this->actingAs($user);

            $this->assertFalse(
                Gate::forUser($user)->allows(
                    AuthorizationProfile::PERMISSION_CLIENT_SENSITIVE,
                ),
                "[{$role}] must not hold staff.clients.sensitive.",
            );
        }

        $this->assertTrue(
            app(AuthorizationProfile::class)->isAdministratorOnlyPermission(
                AuthorizationProfile::PERMISSION_CLIENT_SENSITIVE,
            ),
        );
    }

    public function test_permissions_are_seeded_as_code_owned_and_linked_to_roles(): void
    {
        // staff.clients.search: the four protected roles plus the Management,
        // Client Services Advisor and Receptionist default job roles.
        $expected = [
            'staff.clients.search' => 7,
            'staff.clients.manage' => 2,
            'staff.clients.sensitive' => 1,
        ];

        foreach ($expected as $name => $expectedRoles) {
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
