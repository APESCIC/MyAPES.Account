<?php

namespace Tests\Feature;

use App\Core\Accounts\Role;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use App\Support\DefaultJobRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Recruitment\Models\RecruitmentRole;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesRecruitmentPersonas;
use Tests\TestCase;

class RecruitmentApplicationManageTest extends TestCase
{
    use CreatesRecruitmentPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_signed_in_public_user_can_apply_list_and_withdraw_own_application(): void
    {
        $role = RecruitmentRole::factory()->open()->create([
            'title' => 'Volunteer coordinator',
        ]);
        $applicant = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create();

        $this->actingAs($applicant)
            ->get(route('recruitment.show', $role))
            ->assertOk()
            ->assertSee('action="'.route('recruitment.apply', $role).'"', false)
            ->assertSeeText('Submit application');

        $this->actingAs($applicant)
            ->post(route('recruitment.apply', $role), [
                'statement' => 'I would like to help with volunteer coordination.',
            ])
            ->assertRedirect();

        $application = RecruitmentApplication::query()
            ->where('recruitment_role_id', $role->id)
            ->where('user_id', $applicant->id)
            ->firstOrFail();

        $this->assertSame(RecruitmentApplication::STATUS_SUBMITTED, $application->status);
        $this->assertSame('I would like to help with volunteer coordination.', $application->statement);

        $this->actingAs($applicant)
            ->get(route('recruitment.applications.index'))
            ->assertOk()
            ->assertSeeText('Volunteer coordinator')
            ->assertSeeText('Submitted')
            ->assertSee('href="'.route('recruitment.applications.show', $application).'"', false);

        $this->actingAs($applicant)
            ->get(route('recruitment.applications.show', $application))
            ->assertOk()
            ->assertSeeText('Withdraw application')
            ->assertSee('action="'.route('recruitment.applications.withdraw', $application).'"', false);

        $this->actingAs($applicant)
            ->post(route('recruitment.applications.withdraw', $application))
            ->assertRedirect(route('recruitment.applications.show', $application));

        $application->refresh();
        $this->assertSame(RecruitmentApplication::STATUS_WITHDRAWN, $application->status);
        $this->assertNotNull($application->withdrawn_at);
    }

    public function test_public_user_cannot_apply_twice_or_see_others_applications(): void
    {
        $role = RecruitmentRole::factory()->open()->create();
        $applicant = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create();
        $other = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create();
        $otherApplication = RecruitmentApplication::factory()
            ->forRole($role)
            ->forUser($other)
            ->create([
                'statement' => 'Other applicant private statement',
            ]);

        $this->actingAs($applicant)
            ->post(route('recruitment.apply', $role), [
                'statement' => 'First application.',
            ])
            ->assertRedirect();

        $own = RecruitmentApplication::query()
            ->where('user_id', $applicant->id)
            ->firstOrFail();

        $this->actingAs($applicant)
            ->from(route('recruitment.show', $role))
            ->post(route('recruitment.apply', $role), [
                'statement' => 'Second attempt.',
            ])
            ->assertRedirect(route('recruitment.show', $role))
            ->assertSessionHasErrors('statement');

        $this->assertSame(
            1,
            RecruitmentApplication::query()->where('user_id', $applicant->id)->count(),
        );

        $this->actingAs($applicant)
            ->get(route('recruitment.applications.index'))
            ->assertOk()
            ->assertSeeText($own->recruitmentRole->title)
            ->assertDontSeeText('Other applicant private statement');

        $this->actingAs($applicant)
            ->get(route('recruitment.applications.show', $otherApplication))
            ->assertForbidden();

        $this->actingAs($applicant)
            ->post(route('recruitment.applications.withdraw', $otherApplication))
            ->assertForbidden();
    }

    public function test_guest_is_prompted_to_sign_in_and_cannot_apply(): void
    {
        $role = RecruitmentRole::factory()->open()->create();

        $this->get(route('recruitment.show', $role))
            ->assertOk()
            ->assertSeeText('Sign in or create a public account to apply')
            ->assertSee('href="'.route('public.login').'"', false)
            ->assertSee('href="'.route('public.register').'"', false)
            ->assertDontSee('action="'.route('recruitment.apply', $role).'"', false);

        $this->post(route('recruitment.apply', $role), [
            'statement' => 'Guest attempt.',
        ])->assertRedirect(route('public.login'));

        $this->assertDatabaseMissing('recruitment_applications', [
            'recruitment_role_id' => $role->id,
        ]);

        $this->get(route('recruitment.applications.index'))
            ->assertRedirect(route('public.login'));
    }

    public function test_hr_staff_can_review_application_status_transitions(): void
    {
        $staff = $this->hrStaff();
        $applicant = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create([
                'name' => 'Applicant Person',
                'email' => 'applicant.person@example.test',
            ]);
        $role = RecruitmentRole::factory()->open()->category('volunteer')->create([
            'title' => 'Review target role',
        ]);
        $application = RecruitmentApplication::factory()
            ->forRole($role)
            ->forUser($applicant)
            ->create([
                'statement' => 'Please consider my application.',
            ]);

        $this->actingAs($staff)
            ->get(route('apes-cic.recruitment.applications.index'))
            ->assertOk()
            ->assertSeeText('Review target role')
            ->assertSeeText('Applicant Person')
            ->assertSeeText('Submitted');

        $this->actingAs($staff)
            ->get(route('apes-cic.recruitment.applications.index', [
                'status' => RecruitmentApplication::STATUS_SUBMITTED,
                'category' => 'volunteer',
                'role' => $role->id,
            ]))
            ->assertOk()
            ->assertSeeText('Review target role');

        $this->actingAs($staff)
            ->get(route('apes-cic.recruitment.applications.show', $application))
            ->assertOk()
            ->assertSeeText('Please consider my application.')
            ->assertSeeText('applicant.person@example.test')
            ->assertSee('action="'.route('apes-cic.recruitment.applications.update', $application).'"', false);

        $this->actingAs($staff)
            ->put(route('apes-cic.recruitment.applications.update', $application), [
                'status' => RecruitmentApplication::STATUS_UNDER_REVIEW,
                'staff_notes' => 'Looks promising.',
            ])
            ->assertRedirect(route('apes-cic.recruitment.applications.show', $application));

        $application->refresh();
        $this->assertSame(RecruitmentApplication::STATUS_UNDER_REVIEW, $application->status);
        $this->assertSame('Looks promising.', $application->staff_notes);
        $this->assertNotNull($application->reviewed_at);

        $this->actingAs($staff)
            ->put(route('apes-cic.recruitment.applications.update', $application), [
                'status' => RecruitmentApplication::STATUS_SHORTLISTED,
                'staff_notes' => 'Shortlisted for interview.',
            ])
            ->assertRedirect(route('apes-cic.recruitment.applications.show', $application));

        $application->refresh();
        $this->assertSame(RecruitmentApplication::STATUS_SHORTLISTED, $application->status);

        $this->actingAs($staff)
            ->put(route('apes-cic.recruitment.applications.update', $application), [
                'status' => RecruitmentApplication::STATUS_ACCEPTED,
                'staff_notes' => 'Offer made.',
            ])
            ->assertRedirect(route('apes-cic.recruitment.applications.show', $application));

        $application->refresh();
        $this->assertSame(RecruitmentApplication::STATUS_ACCEPTED, $application->status);
        $this->assertNotNull($application->decided_at);
        $this->assertTrue($application->isTerminal());
    }

    public function test_managers_my_applications_lists_only_their_own_applications(): void
    {
        $role = RecruitmentRole::factory()->open()->create(['title' => 'Shared opening']);
        $someoneElse = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create();
        $othersApplication = RecruitmentApplication::factory()
            ->forRole($role)
            ->forUser($someoneElse)
            ->create(['statement' => 'Someone else statement']);

        foreach ([
            $this->hrStaff(),
            User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create(),
        ] as $manager) {
            $own = RecruitmentApplication::factory()
                ->forRole(RecruitmentRole::factory()->open()->create(['title' => "Own opening {$manager->id}"]))
                ->forUser($manager)
                ->create();

            $this->actingAs($manager)
                ->get(route('recruitment.applications.index'))
                ->assertOk()
                ->assertSeeText("Own opening {$manager->id}")
                ->assertDontSeeText('Shared opening');

            $this->actingAs($manager)
                ->get(route('recruitment.applications.show', $own))
                ->assertOk();
            $this->actingAs($manager)
                ->get(route('recruitment.applications.show', $othersApplication))
                ->assertForbidden();
            $this->actingAs($manager)
                ->post(route('recruitment.applications.withdraw', $othersApplication))
                ->assertForbidden();

            $this->actingAs($manager)
                ->get(route('apes-cic.recruitment.applications.show', $othersApplication))
                ->assertOk()
                ->assertSeeText('Someone else statement');
        }

        $this->assertSame(RecruitmentApplication::STATUS_SUBMITTED, $othersApplication->fresh()->status);
    }

    public function test_applicant_cannot_open_own_application_on_the_staff_review_page(): void
    {
        $applicant = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create();
        $application = RecruitmentApplication::factory()->forUser($applicant)->create();

        $this->actingAs($applicant)
            ->get(route('recruitment.applications.show', $application))
            ->assertOk();
        $this->actingAs($applicant)
            ->get(route('apes-cic.recruitment.applications.show', $application))
            ->assertForbidden();
    }

    public function test_public_user_cannot_access_staff_review_ui(): void
    {
        $applicant = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create();
        $application = RecruitmentApplication::factory()->create();

        $this->actingAs($applicant)
            ->get(route('apes-cic.recruitment.applications.index'))
            ->assertForbidden();

        $this->actingAs($applicant)
            ->get(route('apes-cic.recruitment.applications.show', $application))
            ->assertForbidden();

        $this->actingAs($applicant)
            ->put(route('apes-cic.recruitment.applications.update', $application), [
                'status' => RecruitmentApplication::STATUS_UNDER_REVIEW,
            ])
            ->assertForbidden();
    }

    public function test_hr_staff_without_review_ability_cannot_update_status(): void
    {
        $viewer = $this->hrStaff();

        $permission = Permission::findOrCreate(
            RecruitmentApplication::PERMISSION_PREFIX.'review-applications',
            'web',
        );
        $hrRole = Role::query()
            ->where('name', DefaultJobRoles::HUMAN_RESOURCES)
            ->firstOrFail();
        $hrRole->revokePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $viewer->unsetRelation('roles');
        $viewer->unsetRelation('permissions');

        $application = RecruitmentApplication::factory()->create();

        $this->actingAs($viewer)
            ->get(route('apes-cic.recruitment.applications.index'))
            ->assertOk();

        $this->actingAs($viewer)
            ->get(route('apes-cic.recruitment.applications.show', $application))
            ->assertOk()
            ->assertDontSee('action="'.route('apes-cic.recruitment.applications.update', $application).'"', false);

        $this->actingAs($viewer)
            ->put(route('apes-cic.recruitment.applications.update', $application), [
                'status' => RecruitmentApplication::STATUS_UNDER_REVIEW,
            ])
            ->assertForbidden();

        $application->refresh();
        $this->assertSame(RecruitmentApplication::STATUS_SUBMITTED, $application->status);
    }

    public function test_cannot_apply_to_draft_or_closed_roles_via_http(): void
    {
        $applicant = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create();

        foreach (['draft', 'closed'] as $state) {
            $role = RecruitmentRole::factory()->{$state}()->create();

            $this->actingAs($applicant)
                ->post(route('recruitment.apply', $role), [
                    'statement' => 'Should fail.',
                ])
                ->assertNotFound();

            $this->assertDatabaseMissing('recruitment_applications', [
                'recruitment_role_id' => $role->id,
                'user_id' => $applicant->id,
            ]);
        }
    }
}
