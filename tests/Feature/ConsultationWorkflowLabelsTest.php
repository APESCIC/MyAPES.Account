<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\Consultations\Notifications\ConsultationUpdatedNotification;
use Plugins\PetProfiles\Models\PetProfile;
use Tests\TestCase;

class ConsultationWorkflowLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_consultation_screens_show_title_case_status(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $consultation = $this->consultation($owner);

        $this->actingAs($owner)
            ->get(route('petcare.consultations.index'))
            ->assertOk()
            ->assertSee('<span class="status">In Progress</span>', false)
            ->assertDontSee('>in_progress<', false);

        $html = $this->actingAs($staff)
            ->get(route('petcare.consultations.show', $consultation))
            ->assertOk()
            ->assertSee('<dd>In Progress</dd>', false)
            ->assertSee('<option value="in_progress" selected>In Progress</option>', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/>(open|in_progress|closed)</', $html);
    }

    public function test_consultation_update_email_uses_title_case_status(): void
    {
        $owner = User::factory()->create();

        $mail = (new ConsultationUpdatedNotification($this->consultation($owner), $owner, 'updated'))->toMail($owner);

        $this->assertContains(__('mail.consultation_updated.line_status', ['status' => 'In Progress']), $mail->introLines);
    }

    private function consultation(User $owner): PetCareConsultation
    {
        $pet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Label clinic pet',
            'species' => 'cat',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);

        return PetCareConsultation::query()->create([
            'pet_profile_id' => $pet->id,
            'user_id' => $owner->id,
            'subject' => 'Label consultation',
            'status' => 'in_progress',
            'notes' => 'Consultation used to check workflow labels.',
        ]);
    }
}
