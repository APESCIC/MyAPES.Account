<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Recruitment\Models\RecruitmentRole;
use Tests\Concerns\CreatesRecruitmentPersonas;
use Tests\TestCase;

class RecruitmentWorkflowLabelsTest extends TestCase
{
    use CreatesRecruitmentPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_application_status_labels_come_from_terms_on_public_and_staff_screens(): void
    {
        $staff = $this->hrStaff();
        $applicant = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $role = RecruitmentRole::factory()->open()->category('volunteer')->create(['title' => 'Label role']);
        $application = RecruitmentApplication::factory()->forRole($role)->forUser($applicant)->underReview()->create();

        $this->actingAs($applicant)
            ->get(route('recruitment.applications.index'))
            ->assertOk()
            ->assertSee('<td>Under Review</td>', false);

        $this->actingAs($applicant)
            ->get(route('recruitment.applications.show', $application))
            ->assertOk()
            ->assertSeeText('Status: Under Review');

        $this->actingAs($applicant)
            ->get(route('recruitment.show', $role))
            ->assertOk()
            ->assertSee('<span class="status">Under Review</span>', false)
            ->assertSeeText('Volunteer');

        $html = $this->actingAs($staff)
            ->get(route('apes-cic.recruitment.applications.show', $application))
            ->assertOk()
            ->assertSeeText('Status: Under Review')
            ->assertSee('<dd>Volunteer</dd>', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/>\s*(under_review|submitted|shortlisted|volunteer)\s*</', $html);
    }

    public function test_staff_role_screens_show_title_case_category_and_status(): void
    {
        $staff = $this->hrStaff();
        $role = RecruitmentRole::factory()->draft()->category('student')->create(['title' => 'Draft label role']);

        $index = $this->actingAs($staff)
            ->get(route('apes-cic.recruitment.index'))
            ->assertOk()
            ->assertSee('<td>Student</td>', false)
            ->assertSee('<td>Draft</td>', false)
            ->getContent();
        $this->assertDoesNotMatchRegularExpression('/>\s*(staff|volunteer|student|draft)\s*</', $index);

        $this->actingAs($staff)
            ->get(route('apes-cic.recruitment.show', $role))
            ->assertOk()
            ->assertSeeText('Student | Draft');

        $this->actingAs($staff)
            ->get(route('apes-cic.recruitment.applications.index'))
            ->assertOk()
            ->assertSeeText('Draft label role (Draft)');
    }
}
