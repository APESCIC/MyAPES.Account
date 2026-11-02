<?php

namespace Tests\Feature;

use App\Core\Accounts\DirectoryGroup;
use App\Core\Accounts\Role;
use App\Core\Accounts\User;
use App\Support\DefaultJobRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_workspace_tabs_and_legacy_redirects(): void
    {
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);

        $this->actingAs($superAdmin)
            ->get('/admin/access?tab=groups')
            ->assertOk()
            ->assertSee('Access')
            ->assertSee('Groups')
            ->assertSee('Job roles')
            ->assertSee('Permission catalogue');

        $this->actingAs($superAdmin)
            ->get('/admin/groups')
            ->assertRedirect('/admin/access?tab=groups');

        $this->actingAs($superAdmin)
            ->get('/admin/roles')
            ->assertRedirect('/admin/access?tab=job-roles');

        $this->actingAs($superAdmin)
            ->get('/admin/permissions')
            ->assertRedirect('/admin/access?tab=permissions');

        $this->actingAs($superAdmin)
            ->get('/superadmin')
            ->assertRedirect(route('admin.index'));

        $this->actingAs($superAdmin)
            ->followingRedirects()
            ->get('/superadmin')
            ->assertOk()
            ->assertSee('Access')
            ->assertSee('href="'.route('admin.modules.index').'"', false)
            ->assertDontSee('>Groups</a>', false)
            ->assertDontSee('>Roles</a>', false)
            ->assertDontSee('>Permissions</a>', false)
            ->assertDontSee('href="'.url('/superadmin/plugins').'"', false)
            ->assertDontSee('href="'.url('/superadmin/groups').'"', false);
    }

    public function test_stale_superadmin_section_urls_redirect_to_live_admin_routes(): void
    {
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);
        $staff = $this->userWithAccess(User::ROLE_STAFF);

        $this->get('/superadmin/groups')->assertRedirect(route('public.login'));
        $this->get('/superadmin/plugins')->assertRedirect(route('public.login'));
        $this->get('/superadmin/modules')->assertRedirect(route('public.login'));

        $this->actingAs($staff)->get('/superadmin/groups')->assertForbidden();
        $this->actingAs($staff)->get('/superadmin/plugins')->assertForbidden();
        $this->actingAs($staff)->get('/superadmin/modules')->assertForbidden();

        $this->actingAs($superAdmin)
            ->get('/superadmin/groups')
            ->assertRedirect('/admin/groups');
        $this->actingAs($superAdmin)
            ->followingRedirects()
            ->get('/superadmin/groups')
            ->assertOk()
            ->assertSee('Access')
            ->assertSee('Groups');

        $this->actingAs($superAdmin)
            ->get('/superadmin/plugins')
            ->assertRedirect('/admin/modules');
        $this->actingAs($superAdmin)
            ->get('/superadmin/modules')
            ->assertRedirect('/admin/modules');
        $this->actingAs($superAdmin)
            ->followingRedirects()
            ->get('/superadmin/plugins')
            ->assertOk()
            ->assertSee('module-registry', false);
    }

    public function test_groups_tab_shows_sync_tier_and_job_role_mapping(): void
    {
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);

        $this->actingAs($superAdmin)
            ->get('/admin/access?tab=groups')
            ->assertOk()
            ->assertSee('myapesaccount.staff')
            ->assertSee('Sync from Cloudron')
            ->assertSee('Preset Cloudron mapping')
            ->assertSee('Add job role mapping')
            ->assertDontSee('Enable for this app')
            ->assertDontSee('Disable for this app');
    }

    public function test_job_role_mappings_use_access_routes(): void
    {
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);
        $group = DirectoryGroup::query()
            ->where('name', 'myapesaccount.staff')
            ->firstOrFail();
        $jobRole = Role::query()
            ->where('name', DefaultJobRoles::RECEPTIONIST)
            ->firstOrFail();

        $this->actingAs($superAdmin)
            ->post("/admin/access/groups/{$group->id}/mappings", [
                'role_id' => $jobRole->id,
            ])
            ->assertRedirect('/admin/access?tab=groups');
    }

    public function test_job_roles_tab_lists_defaults_not_protected_tiers(): void
    {
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);

        $this->actingAs($superAdmin)
            ->get('/admin/access?tab=job-roles')
            ->assertOk()
            ->assertSee('Receptionist')
            ->assertSee('Management')
            ->assertDontSee('>super-admin<', false)
            ->assertDontSee('>staff<', false);
    }

    public function test_job_role_show_has_capability_packs_and_advanced(): void
    {
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);
        $role = Role::query()
            ->where('name', DefaultJobRoles::RECEPTIONIST)
            ->firstOrFail();

        $this->actingAs($superAdmin)
            ->get(route('admin.access.job-roles.show', $role))
            ->assertOk()
            ->assertSee('Admin overview')
            ->assertSee('View accounts')
            ->assertSee('Advanced permissions')
            ->assertSee('Update role')
            ->assertSee('<details class="permission-advanced">', false)
            ->assertDontSee('<details class="permission-advanced" open>', false);
    }

    public function test_protected_role_show_returns_not_found(): void
    {
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);
        $role = Role::query()
            ->where('name', 'staff')
            ->firstOrFail();

        $this->actingAs($superAdmin)
            ->get(route('admin.access.job-roles.show', $role))
            ->assertNotFound();
    }

    public function test_permissions_tab_is_read_only_catalogue(): void
    {
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);

        $this->actingAs($superAdmin)
            ->get('/admin/access?tab=permissions&q=users.view')
            ->assertOk()
            ->assertSee('admin.users.view')
            ->assertSee('View accounts')
            ->assertDontSee('Update role');
    }

    public function test_create_job_role_with_advanced_permissions(): void
    {
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);

        $this->actingAs($superAdmin)
            ->post(route('admin.access.job-roles.store'), [
                'name' => 'case-reviewer',
                'permissions' => ['admin.users.view'],
            ])
            ->assertRedirect();

        $role = Role::query()->where('name', 'case-reviewer')->firstOrFail();
        $this->assertTrue(
            $role->permissions()
                ->where('name', 'admin.users.view')
                ->exists(),
        );
    }

    private function userWithAccess(string $accessLevel): User
    {
        return User::factory()->accessLevel($accessLevel)->create()->refresh();
    }
}
