<?php

namespace Tests\Feature;

use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use App\Support\DefaultJobRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Recruitment\Models\RecruitmentRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesRecruitmentPersonas;
use Tests\TestCase;

class RecruitmentDualFrontendIaMatrixTest extends TestCase
{
    use CreatesRecruitmentPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_guest_sees_public_recruitment_not_staff_manage(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('>Recruitment</span>', false)
            ->assertDontSee('>Recruit manage</span>', false)
            ->assertDontSee('href="'.route('apes-cic.recruitment.index').'"', false);

        $this->get(route('recruitment.index'))
            ->assertOk()
            ->assertSeeText('Open roles')
            ->assertDontSeeText('My applications')
            ->assertDontSee('>Recruit manage</span>', false)
            ->assertDontSee('aria-label="Recruitment manage sections"', false);

        $this->get(route('apes-cic.recruitment.index'))
            ->assertRedirect(route('public.login'));
        $this->get(route('apes-cic.recruitment.applications.index'))
            ->assertRedirect(route('public.login'));
    }

    public function test_public_applicant_sees_recruitment_submenu_without_staff_manage(): void
    {
        $applicant = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create();

        $dashboard = $this->actingAs($applicant)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('>Recruit manage</span>', false)
            ->assertDontSee('href="'.route('apes-cic.recruitment.index').'"', false);
        $this->assertSame(route('recruitment.index'), $this->recruitmentPrimaryHref($dashboard->getContent()));

        $this->actingAs($applicant)
            ->get(route('recruitment.index'))
            ->assertOk()
            ->assertSeeText('Open roles')
            ->assertSeeText('My applications')
            ->assertSee('aria-label="Recruitment sections"', false)
            ->assertDontSee('aria-label="Recruitment manage sections"', false);

        $this->actingAs($applicant)
            ->get(route('apes-cic.recruitment.index'))
            ->assertOk()
            ->assertDontSee('>Recruit manage</span>', false)
            ->assertDontSee('action="'.route('apes-cic.recruitment.store').'"', false);

        $this->actingAs($applicant)
            ->get(route('apes-cic.recruitment.applications.index'))
            ->assertForbidden();
    }

    public function test_staff_without_hr_job_role_recruitment_primary_lands_on_open_roles(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $dashboard = $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('>Recruit manage</span>', false);
        $this->assertSame(route('recruitment.index'), $this->recruitmentPrimaryHref($dashboard->getContent()));

        $board = $this->actingAs($staff)
            ->get(route('recruitment.index'))
            ->assertOk();
        $this->assertSame(route('recruitment.index'), $this->recruitmentPrimaryHref($board->getContent()));
        $this->assertMatchesRegularExpression(
            '/class="primary-nav__link is-active"\s+aria-current="page"\s*>\s*<i data-lucide="briefcase"/',
            $board->getContent(),
        );
    }

    public function test_hr_admin_and_superadmin_recruitment_primary_lands_on_roles(): void
    {
        foreach ([
            'hr',
            AuthorizationProfile::ROLE_ADMINISTRATOR,
            AuthorizationProfile::ROLE_SUPER_ADMIN,
        ] as $roleName) {
            $actor = $roleName === 'hr'
                ? $this->hrStaff()
                : User::factory()
                    ->protectedRole($roleName)
                    ->create();

            $dashboard = $this->actingAs($actor)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertDontSee('>Recruit manage</span>', false);
            $this->assertSame(route('apes-cic.recruitment.index'), $this->recruitmentPrimaryHref($dashboard->getContent()));

            $this->actingAs($actor)
                ->get(route('apes-cic.recruitment.index'))
                ->assertOk()
                ->assertSee('aria-label="Recruitment manage sections"', false)
                ->assertSee('>Roles</span>', false)
                ->assertSee('>Applications</span>', false)
                ->assertSee('href="'.route('apes-cic.recruitment.applications.index').'"', false)
                ->assertSee('>Recruitment</span>', false)
                ->assertDontSee('>Recruit manage</span>', false);

            $this->actingAs($actor)
                ->get(route('apes-cic.recruitment.applications.index'))
                ->assertOk()
                ->assertSee('aria-label="Recruitment manage sections"', false)
                ->assertSee('>Roles</span>', false)
                ->assertSee('>Applications</span>', false)
                ->assertDontSee('aria-label="Recruitment sections"', false)
                ->assertDontSeeText('My applications');
        }
    }

    public function test_hr_review_only_sees_applications_submenu_and_roles_crud_fail_closed(): void
    {
        $hr = $this->hrStaff();

        $this->stripHumanResourcesPermissionsExceptReview();

        $hr = $hr->fresh();
        $hr->unsetRelation('roles');
        $hr->unsetRelation('permissions');

        $role = RecruitmentRole::factory()->create();
        $application = RecruitmentApplication::factory()->create();

        $dashboard = $this->actingAs($hr)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('href="'.route('apes-cic.recruitment.index').'"', false);
        $this->assertSame(route('apes-cic.recruitment.applications.index'), $this->recruitmentPrimaryHref($dashboard->getContent()));

        $this->actingAs($hr)
            ->get(route('apes-cic.recruitment.applications.index'))
            ->assertOk()
            ->assertSee('aria-label="Recruitment manage sections"', false)
            ->assertSee('>Applications</span>', false)
            ->assertDontSee('>Roles</span>', false)
            ->assertDontSeeText('My applications');

        $this->actingAs($hr)
            ->get(route('apes-cic.recruitment.index'))
            ->assertForbidden();

        $this->actingAs($hr)
            ->post(route('apes-cic.recruitment.store'), [
                'title' => 'HR should not create',
                'description' => 'Denied',
                'category' => 'staff',
            ])
            ->assertForbidden();

        $this->actingAs($hr)
            ->put(route('apes-cic.recruitment.update', $role), [
                'title' => 'HR should not update',
                'description' => 'Denied',
                'category' => 'staff',
            ])
            ->assertForbidden();

        $this->actingAs($hr)
            ->get(route('apes-cic.recruitment.applications.show', $application))
            ->assertOk();
    }

    public function test_students_and_volunteers_are_denied_recruitment(): void
    {
        $role = RecruitmentRole::factory()->open()->create();
        $application = RecruitmentApplication::factory()->forRole($role)->create();

        foreach ([
            AuthorizationProfile::ROLE_STUDENT,
            AuthorizationProfile::ROLE_VOLUNTEER,
        ] as $roleName) {
            $actor = User::factory()
                ->protectedRole($roleName)
                ->create();

            $this->actingAs($actor)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertDontSee('>Recruitment</span>', false)
                ->assertDontSee('>Recruit manage</span>', false)
                ->assertDontSee('href="'.route('recruitment.index').'"', false);

            foreach ([
                route('recruitment.index'),
                route('recruitment.show', $role),
                route('recruitment.applications.index'),
                route('recruitment.applications.show', $application),
                route('apes-cic.recruitment.index'),
                route('apes-cic.recruitment.applications.index'),
                route('apes-cic.recruitment.applications.show', $application),
            ] as $url) {
                $this->actingAs($actor)->get($url)->assertForbidden();
            }

            $this->actingAs($actor)
                ->post(route('recruitment.apply', $role), ['statement' => 'Denied'])
                ->assertForbidden();
            $this->actingAs($actor)
                ->post(route('apes-cic.recruitment.store'), [
                    'title' => 'Denied',
                    'description' => 'Denied',
                    'category' => 'student',
                ])
                ->assertForbidden();
        }

        $this->assertSame(1, RecruitmentApplication::query()->count());
    }

    public function test_signed_in_service_user_without_service_selection_can_still_browse_open_roles(): void
    {
        $applicant = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create();
        $applicant->serviceSelections()->delete();
        $role = RecruitmentRole::factory()->open()->create(['title' => 'Browsable role']);

        $this->actingAs($applicant)
            ->get(route('recruitment.index'))
            ->assertOk()
            ->assertSeeText('Browsable role');
        $this->actingAs($applicant)
            ->get(route('recruitment.show', $role))
            ->assertOk();
    }

    public function test_staff_manage_primary_hidden_when_recruitment_module_disabled(): void
    {
        $staff = $this->hrStaff();

        ModuleInstallation::query()
            ->where('sub_core_key', 'apes-cic')
            ->where('module_key', 'recruitment')
            ->update(['enabled' => false]);

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('>Recruit manage</span>', false);

        $this->actingAs($staff)
            ->get('/apes-cic/recruitment')
            ->assertNotFound();
        $this->actingAs($staff)
            ->get('/apes-cic/recruitment/applications')
            ->assertNotFound();
    }

    public function test_apes_cic_hub_still_links_to_recruitment_manage_for_hr(): void
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

    private function stripHumanResourcesPermissionsExceptReview(): void
    {
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', [AuthorizationProfile::ROLE_STAFF, DefaultJobRoles::HUMAN_RESOURCES])
            ->get();

        $recruitmentPermissions = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'like', 'apes-cic.recruitment.%')
            ->where('name', '!=', RecruitmentApplication::PERMISSION_PREFIX.'review-applications')
            ->get();

        foreach ($roles as $role) {
            foreach ($recruitmentPermissions as $permission) {
                $role->revokePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function recruitmentPrimaryHref(string $html): string
    {
        $count = preg_match_all(
            '/<a href="([^"]+)"[^>]*class="primary-nav__link[^"]*"[^>]*>\s*<i[^>]*><\/i>\s*<span>Recruitment<\/span>/',
            $html,
            $matches,
        );

        $this->assertSame(1, $count, 'Expected exactly one Recruitment primary.');

        return html_entity_decode($matches[1][0], ENT_QUOTES);
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
