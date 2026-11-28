<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\AuthorizationRoleMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityAndPublicProfilesPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_and_public_profiles_permissions_are_in_the_matrix(): void
    {
        $matrix = app(AuthorizationProfile::class)->permissionMatrix();
        $admin = $matrix[AuthorizationProfile::ROLE_ADMINISTRATOR];

        foreach ([
            'admin.security.view',
            'admin.security.manage',
            'admin.public-profiles.view',
            'admin.public-profiles.manage',
        ] as $permission) {
            $this->assertContains($permission, $admin);
        }
    }

    public function test_users_without_security_or_public_profiles_permissions_get_403(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        foreach ([
            route('admin.security.index'),
            route('admin.security.audit-log'),
            route('admin.security.keys-and-secrets'),
            route('admin.security.sign-in-policy'),
            route('admin.public-profiles.index'),
        ] as $url) {
            $this->actingAs($staff)->get($url)->assertForbidden();
        }
    }

    public function test_administrator_can_view_security_and_export_requires_manage(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($admin)->get(route('admin.security.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.security.audit-log.export'))->assertOk();
        $this->actingAs($staff)->get(route('admin.security.audit-log.export'))->assertForbidden();
    }
}
