<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\PetProfiles\Models\PetProfile;
use Tests\TestCase;

/**
 * One shared pet record across Shelter and Rescue and Pet Care Clinic (#482).
 */
class SharedPetRecordsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_a_pet_added_in_shelter_appears_in_pet_care_and_can_have_a_consultation(): void
    {
        $owner = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        foreach (['shelter-rescue', 'pet-care-clinic'] as $service) {
            $owner->serviceSelections()->firstOrCreate(['sub_core_key' => $service]);
        }

        $this->actingAs($owner)
            ->post(route('shelter.pets.store'), [
                'name' => 'Shared Tortoise',
                'species' => 'tortoise',
                'sex' => 'female',
                'neutering_status' => 'unknown',
            ])
            ->assertRedirect();
        $pet = PetProfile::query()->where('name', 'Shared Tortoise')->sole();
        $this->assertSame(PetProfile::DOMAIN_SHELTER, $pet->service_domain);

        $this->actingAs($owner)
            ->get(route('petcare.pets.index'))
            ->assertOk()
            ->assertSeeText('Shared Tortoise');
        $this->actingAs($owner)->get(route('petcare.pets.show', $pet))->assertOk();

        $vet = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $this->actingAs($vet)
            ->get(route('petcare.pets.index'))
            ->assertOk()
            ->assertSeeText('Shared Tortoise');

        $this->actingAs($vet)
            ->post(route('petcare.consultations.store'), [
                'pet_profile_id' => $pet->id,
                'subject' => 'Shell check',
            ])
            ->assertRedirect();

        $consultation = PetCareConsultation::query()->where('pet_profile_id', $pet->id)->sole();
        $this->actingAs($vet)
            ->get(route('petcare.consultations.show', $consultation))
            ->assertOk()
            ->assertSeeText('Shell check');
    }

    public function test_a_pet_care_pet_appears_in_shelter_and_existing_pets_are_kept(): void
    {
        $owner = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $shelterPet = $this->pet($owner, PetProfile::DOMAIN_SHELTER, 'Origin Shelter');
        $careP = $this->pet($owner, PetProfile::DOMAIN_PETCARE, 'Origin Pet Care');
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        foreach (['shelter.pets.index', 'petcare.pets.index'] as $route) {
            $this->actingAs($staff)
                ->get(route($route))
                ->assertOk()
                ->assertSeeText('Origin Shelter')
                ->assertSeeText('Origin Pet Care');
        }

        $this->actingAs($staff)->get(route('shelter.pets.show', $careP))->assertOk();
        $this->assertSame(2, PetProfile::query()->count());
        $this->assertSame(PetProfile::DOMAIN_SHELTER, $shelterPet->fresh()->service_domain);
        $this->assertSame(PetProfile::DOMAIN_PETCARE, $careP->fresh()->service_domain);
    }

    public function test_people_still_only_see_their_own_pets(): void
    {
        $owner = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $stranger = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $pet = $this->pet($owner, PetProfile::DOMAIN_SHELTER, 'Private Gecko');

        $this->actingAs($stranger)->get(route('petcare.pets.index'))->assertOk()->assertDontSeeText('Private Gecko');
        $this->actingAs($stranger)->get(route('petcare.pets.show', $pet))->assertForbidden();
    }

    private function pet(User $owner, string $domain, string $name): PetProfile
    {
        return PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => $domain,
            'name' => $name,
            'species' => 'lizard',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);
    }
}
