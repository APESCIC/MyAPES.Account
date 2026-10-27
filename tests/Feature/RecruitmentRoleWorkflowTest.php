<?php

namespace Tests\Feature;

use App\Contracts\ModuleRegistry;
use App\Core\Accounts\Role;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use App\Support\DefaultJobRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Recruitment\Dashboard\RecruitmentActiveRecordDetector;
use Plugins\Recruitment\Models\RecruitmentRole;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesRecruitmentPersonas;
use Tests\TestCase;

class RecruitmentRoleWorkflowTest extends TestCase
{
    use CreatesRecruitmentPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_hr_staff_can_create_edit_publish_and_close_roles_in_all_categories(): void
    {
        $staff = $this->hrStaff();

        foreach (RecruitmentRole::CATEGORIES as $category) {
            $response = $this->actingAs($staff)->post(route('apes-cic.recruitment.store'), [
                'title' => "Open {$category} role",
                'summary' => "Summary for {$category}",
                'description' => "Description for {$category}",
                'category' => $category,
                'location' => 'London',
                'commitment' => 'Part-time',
            ]);

            $role = RecruitmentRole::query()
                ->where('title', "Open {$category} role")
                ->firstOrFail();

            $response->assertRedirect(route('apes-cic.recruitment.show', $role));
            $this->assertSame(RecruitmentRole::STATUS_DRAFT, $role->status);
            $this->assertSame($staff->id, $role->created_by);
            $this->assertSame($category, $role->category);

            $this->actingAs($staff)
                ->put(route('apes-cic.recruitment.update', $role), [
                    'title' => "Updated {$category} role",
                    'summary' => "Updated summary for {$category}",
                    'description' => "Updated description for {$category}",
                    'category' => $category,
                    'location' => 'Manchester',
                    'commitment' => 'Flexible',
                ])
                ->assertRedirect(route('apes-cic.recruitment.show', $role));

            $role->refresh();
            $this->assertSame("Updated {$category} role", $role->title);
            $this->assertSame('Manchester', $role->location);

            $this->actingAs($staff)
                ->post(route('apes-cic.recruitment.publish', $role))
                ->assertRedirect(route('apes-cic.recruitment.show', $role));

            $role->refresh();
            $this->assertSame(RecruitmentRole::STATUS_OPEN, $role->status);
            $this->assertNotNull($role->published_at);
            $this->assertNull($role->closed_at);

            $this->actingAs($staff)
                ->post(route('apes-cic.recruitment.close', $role))
                ->assertRedirect(route('apes-cic.recruitment.show', $role));

            $role->refresh();
            $this->assertSame(RecruitmentRole::STATUS_CLOSED, $role->status);
            $this->assertNotNull($role->closed_at);
        }
    }

    public function test_hr_staff_index_lists_roles_and_shows_create_form(): void
    {
        $staff = $this->hrStaff();
        $role = RecruitmentRole::factory()
            ->for($staff, 'creator')
            ->create(['title' => 'Listed staff role']);

        $this->actingAs($staff)
            ->get(route('apes-cic.recruitment.index'))
            ->assertOk()
            ->assertSee('id="list"', false)
            ->assertSee('id="create"', false)
            ->assertSee('Listed staff role')
            ->assertSee('action="'.route('apes-cic.recruitment.store').'"', false);
    }

    public function test_apes_cic_hub_quick_links_for_hr_point_at_recruitment_list_and_create(): void
    {
        $staff = $this->hrStaff();

        $html = $this->actingAs($staff)
            ->get('/apes-cic')
            ->assertOk()
            ->getContent();

        $listUrl = route('apes-cic.recruitment.index');
        $this->assertSame($listUrl.'#list', $this->hubActionHref($html, 'view', 'recruitment'));
        $this->assertSame($listUrl.'#create', $this->hubActionHref($html, 'create', 'recruitment'));
    }

    public function test_plain_staff_without_hr_job_role_cannot_manage_recruitment(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $role = RecruitmentRole::factory()->draft()->create(['title' => 'HR only draft']);

        $this->actingAs($staff)
            ->post(route('apes-cic.recruitment.store'), [
                'title' => 'Plain staff role',
                'description' => 'Denied',
                'category' => 'staff',
            ])
            ->assertForbidden();
        $this->actingAs($staff)
            ->put(route('apes-cic.recruitment.update', $role), [
                'title' => 'Plain staff rewrite',
                'description' => 'Denied',
                'category' => 'staff',
            ])
            ->assertForbidden();
        $this->actingAs($staff)
            ->post(route('apes-cic.recruitment.publish', $role))
            ->assertForbidden();
        $this->actingAs($staff)
            ->get(route('apes-cic.recruitment.applications.index'))
            ->assertForbidden();

        $this->assertDatabaseMissing('recruitment_roles', ['title' => 'Plain staff role']);
        $this->assertSame('HR only draft', $role->fresh()->title);
        $this->assertSame(RecruitmentRole::STATUS_DRAFT, $role->fresh()->status);
    }

    public function test_guests_cannot_mutate_recruitment_roles(): void
    {
        $role = RecruitmentRole::factory()->create();

        $this->get(route('apes-cic.recruitment.index'))
            ->assertRedirect(route('public.login'));
        $this->post(route('apes-cic.recruitment.store'), [
            'title' => 'Guest role',
            'description' => 'Should not persist',
            'category' => 'volunteer',
        ])->assertRedirect(route('public.login'));
        $this->put(route('apes-cic.recruitment.update', $role), [
            'title' => 'Guest update',
            'description' => 'Should not persist',
            'category' => 'volunteer',
        ])->assertRedirect(route('public.login'));
        $this->post(route('apes-cic.recruitment.publish', $role))
            ->assertRedirect(route('public.login'));
        $this->post(route('apes-cic.recruitment.close', $role))
            ->assertRedirect(route('public.login'));

        $this->assertDatabaseMissing('recruitment_roles', [
            'title' => 'Guest role',
        ]);
        $this->assertSame($role->title, $role->fresh()->title);
    }

    public function test_public_service_user_cannot_create_or_edit_roles(): void
    {
        $public = User::factory()->create();
        $role = RecruitmentRole::factory()->create([
            'title' => 'Protected role',
        ]);

        $this->actingAs($public)
            ->get(route('apes-cic.recruitment.index'))
            ->assertOk()
            ->assertDontSee('action="'.route('apes-cic.recruitment.store').'"', false);

        $this->actingAs($public)
            ->post(route('apes-cic.recruitment.store'), [
                'title' => 'Public created role',
                'description' => 'Should be denied',
                'category' => 'student',
            ])
            ->assertForbidden();

        $this->actingAs($public)
            ->get(route('apes-cic.recruitment.show', $role))
            ->assertForbidden();

        $this->actingAs($public)
            ->put(route('apes-cic.recruitment.update', $role), [
                'title' => 'Public rewrite',
                'description' => 'Should be denied',
                'category' => 'student',
            ])
            ->assertForbidden();

        $this->actingAs($public)
            ->post(route('apes-cic.recruitment.publish', $role))
            ->assertForbidden();

        $this->assertDatabaseMissing('recruitment_roles', [
            'title' => 'Public created role',
        ]);
        $this->assertSame('Protected role', $role->fresh()->title);
    }

    public function test_hr_staff_without_create_cannot_store_and_form_is_hidden(): void
    {
        $staff = $this->hrStaff();
        $this->removeRolePermission(
            DefaultJobRoles::HUMAN_RESOURCES,
            'apes-cic.recruitment.create',
        );

        $this->actingAs($staff->fresh())
            ->get(route('apes-cic.recruitment.index'))
            ->assertOk()
            ->assertDontSee('action="'.route('apes-cic.recruitment.store').'"', false);

        $this->post(route('apes-cic.recruitment.store'), [
            'title' => 'Forbidden create',
            'description' => 'Denied',
            'category' => 'staff',
        ])->assertForbidden();

        $this->assertDatabaseMissing('recruitment_roles', [
            'title' => 'Forbidden create',
        ]);
    }

    public function test_hr_staff_without_update_cannot_publish_or_close(): void
    {
        $staff = $this->hrStaff();
        $role = RecruitmentRole::factory()
            ->for($staff, 'creator')
            ->draft()
            ->create(['title' => 'Locked draft']);
        $this->removeRolePermission(
            DefaultJobRoles::HUMAN_RESOURCES,
            'apes-cic.recruitment.update',
        );

        $this->actingAs($staff->fresh())
            ->get(route('apes-cic.recruitment.show', $role))
            ->assertOk()
            ->assertDontSee('action="'.route('apes-cic.recruitment.publish', $role).'"', false)
            ->assertDontSee('action="'.route('apes-cic.recruitment.update', $role).'"', false);

        $this->post(route('apes-cic.recruitment.publish', $role))->assertForbidden();
        $this->assertSame(RecruitmentRole::STATUS_DRAFT, $role->fresh()->status);

        $openRole = RecruitmentRole::factory()
            ->for($staff, 'creator')
            ->open()
            ->create(['title' => 'Locked open']);

        $this->post(route('apes-cic.recruitment.close', $openRole))->assertForbidden();
        $this->assertSame(RecruitmentRole::STATUS_OPEN, $openRole->fresh()->status);
    }

    public function test_active_record_detector_counts_roles(): void
    {
        RecruitmentRole::factory()->count(3)->create();

        $detector = app(RecruitmentActiveRecordDetector::class);
        $instance = app(ModuleRegistry::class)
            ->instance('apes-cic', 'recruitment');

        $this->assertSame(3, $detector->count($instance));
    }

    private function removeRolePermission(string $roleName, string $permissionName): void
    {
        $role = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $roleName)
            ->firstOrFail();
        $permission = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', $permissionName)
            ->firstOrFail();
        $role->permissions()->detach($permission->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function hubActionHref(string $html, string $action, string $moduleKey): string
    {
        $pattern = sprintf(
            '/<a[^>]*href="([^"]+)"[^>]*data-hub-action="%s"[^>]*data-module-key="%s"/',
            preg_quote($action, '/'),
            preg_quote($moduleKey, '/'),
        );

        $this->assertSame(
            1,
            preg_match($pattern, $html, $matches),
            "Missing hub {$action} link for {$moduleKey}.",
        );

        return html_entity_decode($matches[1], ENT_QUOTES);
    }
}
