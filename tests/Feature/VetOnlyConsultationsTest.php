<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\PetProfiles\Models\PetProfile;
use Tests\Concerns\CreatesClinicalPersonas;
use Tests\TestCase;

class VetOnlyConsultationsTest extends TestCase
{
    use CreatesClinicalPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Notification::fake();
    }

    public function test_vet_vet_nurse_and_admin_create_consultations_for_a_clients_pet(): void
    {
        $owner = User::factory()->create();
        $pet = $this->petFor($owner);
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        foreach (['vet' => $this->vetStaff(), 'nurse' => $this->vetNurseStaff(), 'admin' => $administrator] as $label => $clinician) {
            $this->actingAs($clinician)
                ->post(route('petcare.consultations.store'), [
                    'pet_profile_id' => $pet->id,
                    'subject' => "Created by {$label}",
                ])
                ->assertRedirect();

            $this->assertDatabaseHas('pet_care_consultations', [
                'subject' => "Created by {$label}",
                'user_id' => $owner->id,
            ]);
        }
    }

    public function test_public_users_and_non_clinical_staff_cannot_create(): void
    {
        $owner = User::factory()->create();
        $pet = $this->petFor($owner);

        foreach ([
            $owner,
            User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create(),
            User::factory()->protectedRole(AuthorizationProfile::ROLE_VOLUNTEER)->create(),
            User::factory()->protectedRole(AuthorizationProfile::ROLE_STUDENT)->create(),
        ] as $user) {
            $this->actingAs($user)
                ->post(route('petcare.consultations.store'), [
                    'pet_profile_id' => $pet->id,
                    'subject' => 'Not allowed',
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseMissing('pet_care_consultations', ['subject' => 'Not allowed']);
    }

    public function test_only_clinical_roles_edit_and_clients_cannot_edit_their_own(): void
    {
        $owner = User::factory()->create();
        $consultation = $this->consultationFor($owner, $this->petFor($owner));

        foreach ([
            $owner,
            User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create(),
            User::factory()->protectedRole(AuthorizationProfile::ROLE_VOLUNTEER)->create(),
            User::factory()->protectedRole(AuthorizationProfile::ROLE_STUDENT)->create(),
        ] as $user) {
            $this->actingAs($user)
                ->patch(route('petcare.consultations.update', $consultation), ['notes' => 'Edited'])
                ->assertForbidden();
        }
        $this->assertSame('Consultation notes.', $consultation->fresh()->notes);

        $this->actingAs($this->vetNurseStaff())
            ->patch(route('petcare.consultations.update', $consultation), ['notes' => 'Edited by the nurse'])
            ->assertRedirect(route('petcare.consultations.show', $consultation));
        $this->assertSame('Edited by the nurse', $consultation->fresh()->notes);
    }

    public function test_non_clinical_staff_still_view_assign_and_close(): void
    {
        $owner = User::factory()->create();
        $consultation = $this->consultationFor($owner, $this->petFor($owner));
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $volunteer = User::factory()->protectedRole(AuthorizationProfile::ROLE_VOLUNTEER)->create();

        $this->actingAs($volunteer)
            ->get(route('petcare.consultations.show', $consultation))
            ->assertOk();
        $this->actingAs($staff)
            ->patch(route('petcare.consultations.update', $consultation), ['assigned_to' => $volunteer->id])
            ->assertRedirect();
        $this->actingAs($staff)
            ->patch(route('petcare.consultations.update', $consultation), ['status' => 'closed'])
            ->assertRedirect();

        $consultation->refresh();
        $this->assertSame($volunteer->id, $consultation->assigned_to);
        $this->assertSame('closed', $consultation->status);
    }

    private function petFor(User $owner, string $domain = PetProfile::DOMAIN_PETCARE): PetProfile
    {
        return PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => $domain,
            'name' => 'Pico',
            'species' => 'cat',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function consultationFor(User $owner, PetProfile $pet, array $attributes = []): PetCareConsultation
    {
        return PetCareConsultation::query()->create(array_merge([
            'pet_profile_id' => $pet->id,
            'user_id' => $owner->id,
            'subject' => 'Annual check',
            'status' => 'open',
            'notes' => 'Consultation notes.',
        ], $attributes))->fresh();
    }
}
