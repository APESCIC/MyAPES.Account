<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\PetProfiles\Support\StaffPetCreateReturn;
use Tests\Concerns\CreatesClinicalPersonas;
use Tests\TestCase;

class StaffEmptyPetSelectTest extends TestCase
{
    use CreatesClinicalPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    /**
     * @return array<string, array{0: array{
     *     createIndexRoute: string,
     *     petsIndexRoute: string,
     *     petsStoreRoute: string,
     *     returnKey: string,
     *     domain: string,
     *     clinical: bool
     * }}>
     */
    public static function staffCreateFlows(): array
    {
        return [
            'shelter cases' => [[
                'createIndexRoute' => 'shelter.cases.index',
                'petsIndexRoute' => 'shelter.pets.index',
                'petsStoreRoute' => 'shelter.pets.store',
                'returnKey' => StaffPetCreateReturn::SHELTER_CASES,
                'domain' => PetProfile::DOMAIN_SHELTER,
                'clinical' => false,
            ]],
            'pet care consultations' => [[
                'createIndexRoute' => 'petcare.consultations.index',
                'petsIndexRoute' => 'petcare.pets.index',
                'petsStoreRoute' => 'petcare.pets.store',
                'returnKey' => StaffPetCreateReturn::PETCARE_CONSULTATIONS,
                'domain' => PetProfile::DOMAIN_PETCARE,
                'clinical' => true,
            ]],
        ];
    }

    /**
     * Consultations are created only by Vet, Vet Nurse and admins (#536).
     *
     * @param  array{clinical: bool}  $flow
     */
    private function creatorFor(array $flow): User
    {
        return $flow['clinical']
            ? $this->vetStaff()
            : User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
    }

    /**
     * @param  array{
     *     createIndexRoute: string,
     *     petsIndexRoute: string,
     *     petsStoreRoute: string,
     *     returnKey: string,
     *     domain: string,
     *     clinical: bool
     * }  $flow
     */
    #[DataProvider('staffCreateFlows')]
    public function test_staff_empty_pet_select_explains_the_gap_without_an_add_pet_link(
        array $flow,
    ): void {
        $staff = $this->creatorFor($flow);

        $this->actingAs($staff)
            ->get(route($flow['createIndexRoute']))
            ->assertOk()
            ->assertSee('data-empty-pet-select', false)
            ->assertSeeText('No pet profiles are available yet.')
            ->assertDontSee('data-add-pet-first', false)
            ->assertDontSeeText('Add a pet first')
            ->assertDontSee('name="pet_profile_id"', false);

        $this->post(route($flow['petsStoreRoute']), [
            'name' => 'Staff-owned pet',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
            'return_to' => $flow['returnKey'],
        ])->assertForbidden();

        $this->assertSame(0, PetProfile::query()->count());
    }

    /**
     * @param  array{
     *     createIndexRoute: string,
     *     petsIndexRoute: string,
     *     petsStoreRoute: string,
     *     returnKey: string,
     *     domain: string,
     *     clinical: bool
     * }  $flow
     */
    #[DataProvider('staffCreateFlows')]
    public function test_public_empty_pet_select_stays_a_blank_dropdown_without_add_pet_copy(
        array $flow,
    ): void {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)
            ->get(route($flow['createIndexRoute']))
            ->assertOk()
            ->assertDontSee('data-empty-pet-select', false)
            ->assertDontSeeText('Add a pet first')
            ->assertDontSee('return_to='.$flow['returnKey']);

        if ($flow['clinical']) {
            $response->assertDontSee('name="pet_profile_id"', false);
        } else {
            $response->assertSee('name="pet_profile_id"', false);
        }
    }

    /**
     * @param  array{
     *     createIndexRoute: string,
     *     petsIndexRoute: string,
     *     petsStoreRoute: string,
     *     returnKey: string,
     *     domain: string,
     *     clinical: bool
     * }  $flow
     */
    #[DataProvider('staffCreateFlows')]
    public function test_staff_with_client_pets_keep_the_dropdown_and_do_not_see_the_empty_state(
        array $flow,
    ): void {
        $client = User::factory()->create();
        $staff = $this->creatorFor($flow);
        $pet = PetProfile::query()->create([
            'user_id' => $client->id,
            'service_domain' => $flow['domain'],
            'name' => 'Existing client pet',
            'species' => 'dog',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);

        $this->actingAs($staff)
            ->get(route($flow['createIndexRoute']))
            ->assertOk()
            ->assertDontSee('data-empty-pet-select', false)
            ->assertDontSeeText('Add a pet first')
            ->assertSee('name="pet_profile_id"', false)
            ->assertSeeText('Existing client pet')
            ->assertSee('value="'.$pet->id.'"', false);
    }

    public function test_unsafe_return_to_does_not_redirect_away_from_the_new_pet(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post(route('shelter.pets.store'), [
            'name' => 'Unsafe return pet',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
            'return_to' => 'https://example.invalid/phish',
        ]);

        $pet = PetProfile::query()->where('name', 'Unsafe return pet')->firstOrFail();
        $response->assertRedirect(route('shelter.pets.show', $pet));
    }
}
