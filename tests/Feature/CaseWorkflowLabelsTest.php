<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Cases\Notifications\ApesCicCaseUpdatedNotification;
use Plugins\Cases\Notifications\ShelterCaseUpdatedNotification;
use Plugins\PetProfiles\Models\PetProfile;
use Tests\TestCase;

class CaseWorkflowLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_apes_cic_case_screens_show_title_case_status_and_priority(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $case = $this->apesCicCase($owner);

        $this->actingAs($owner)
            ->get(route('apes-cic.cases.index'))
            ->assertOk()
            ->assertSee('<span class="status">Waiting on User</span>', false)
            ->assertSee('<td>Urgent</td>', false)
            ->assertDontSee('>waiting_on_user<', false);

        $html = $this->actingAs($staff)
            ->get(route('apes-cic.cases.show', $case))
            ->assertOk()
            ->assertSee('<span class="status">Waiting on User</span>', false)
            ->assertSee('<dd>Urgent priority</dd>', false)
            ->assertSee('<option value="waiting_on_user" selected>Waiting on User</option>', false)
            ->assertSee('<option value="in_progress" >In Progress</option>', false)
            ->assertSee('<option value="urgent" selected>Urgent</option>', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/>(open|in_progress|waiting_on_user|waiting on user|resolved|closed|low|medium|high|urgent)</', $html);
    }

    public function test_shelter_case_screens_show_title_case_status_and_case_type(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $case = $this->shelterCase($owner);

        $this->actingAs($owner)
            ->get(route('shelter.cases.index'))
            ->assertOk()
            ->assertSee('<td>Surrender</td>', false)
            ->assertSee('<span class="status">In Review</span>', false)
            ->assertSee('<option value="fostering">Fostering</option>', false);

        $html = $this->actingAs($staff)
            ->get(route('shelter.cases.show', $case))
            ->assertOk()
            ->assertSee('Type: Surrender')
            ->assertSee('Status: In Review')
            ->assertSee('<option value="in_review" selected>In Review</option>', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/>(open|in_review|closed|adoption|surrender|rescue|fostering)</', $html);
    }

    public function test_case_update_emails_use_title_case_labels(): void
    {
        $owner = User::factory()->create();

        $apesCicMail = (new ApesCicCaseUpdatedNotification(
            $this->apesCicCase($owner),
            $owner,
            'updated',
            'apes-cic',
            'apes-cic.cases.show',
        ))->toMail($owner);
        $this->assertContains(__('mail.case_updated.line_status', ['status' => 'Waiting on User']), $apesCicMail->introLines);
        $this->assertContains(__('mail.case_updated.line_priority', ['priority' => 'Urgent']), $apesCicMail->introLines);

        $shelterMail = (new ShelterCaseUpdatedNotification($this->shelterCase($owner), $owner, 'updated'))->toMail($owner);
        $this->assertContains(__('mail.shelter_case_updated.line_status', ['status' => 'In Review']), $shelterMail->introLines);
    }

    private function apesCicCase(User $owner): ShelterCase
    {
        return ShelterCase::query()->create([
            'sub_core_key' => ShelterCase::SUB_CORE_APES_CIC,
            'user_id' => $owner->id,
            'category' => 'general',
            'priority' => 'urgent',
            'status' => 'waiting_on_user',
            'title' => 'APES CIC label case',
            'details' => 'Case used to check workflow labels.',
        ]);
    }

    private function shelterCase(User $owner): ShelterCase
    {
        $pet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_SHELTER,
            'name' => 'Label pet',
            'species' => 'dog',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);

        return ShelterCase::query()->create([
            'sub_core_key' => ShelterCase::SUB_CORE_SHELTER_RESCUE,
            'pet_profile_id' => $pet->id,
            'user_id' => $owner->id,
            'case_type' => 'surrender',
            'status' => 'in_review',
            'title' => 'Shelter label case',
            'details' => 'Case used to check workflow labels.',
        ]);
    }
}
