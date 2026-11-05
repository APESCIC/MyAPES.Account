<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\Eloquent\MorphMap;
use App\Core\Extensions\Plugins\PluginRegistry;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Plugins\PetProfiles\Contracts\PetProfilesContract;
use Plugins\PetProfiles\Http\Controllers\PetProfileController;
use Plugins\PetProfiles\Models\PetProfile;
use Tests\TestCase;

class StructurePetProfilesWave5Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_single_shared_controller_serves_both_module_pet_routes(): void
    {
        foreach ([
            'shelter.pets.index',
            'shelter.pets.store',
            'shelter.pets.show',
            'shelter.pets.update',
            'shelter.pets.photo',
            'petcare.pets.index',
            'petcare.pets.store',
            'petcare.pets.show',
            'petcare.pets.update',
            'petcare.pets.photo',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Missing route {$name}");
            $action = Route::getRoutes()->getByName($name)->getActionName();
            $this->assertStringContainsString(PetProfileController::class, $action);
        }

        $this->assertFileDoesNotExist(app_path('Http/Controllers/Shelter/PetProfileController.php'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/PetCare/PetProfileController.php'));
        $this->assertFileDoesNotExist(app_path('Models/PetProfile.php'));
    }

    public function test_live_pet_urls_and_legacy_aliases_remain(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($staff)->get('/shelter/pets')->assertOk();
        $this->actingAs($staff)->get('/petcare/pets')->assertOk();

        $this->actingAs($staff)
            ->get('/shelter/pet-profiles')
            ->assertRedirect(route('shelter.pets.index'));
        $this->actingAs($staff)
            ->get('/petcare/pet-profiles')
            ->assertRedirect(route('petcare.pets.index'));

        $this->get('/storage/pet-profiles/demo.jpg')->assertNotFound();
    }

    public function test_morph_alias_points_at_plugin_model(): void
    {
        $this->assertSame(PetProfile::class, MorphMap::aliases()['pet_profile']);
        $this->assertSame(MorphMap::aliases(), Relation::morphMap());
        $this->assertSame('pet_profile', MorphMap::aliasFor(PetProfile::class));
    }

    public function test_plugin_manifest_and_public_contract_are_registered(): void
    {
        $manifest = app(PluginRegistry::class)->plugin('pet-profiles');
        $this->assertSame(['shelter-rescue', 'pet-care-clinic'], $manifest->compatibleModules);
        $this->assertSame(['shelter-rescue', 'pet-care-clinic'], $manifest->shippedModules);
        $this->assertSame('pet_profiles', $manifest->translationNamespace);

        $this->assertInstanceOf(
            PetProfilesContract::class,
            app(PetProfilesContract::class),
        );
    }

    public function test_clients_create_pets_in_both_modules_and_staff_can_view_them(): void
    {
        $client = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($client)->post(route('shelter.pets.store'), [
            'name' => 'Wave5 Shelter Pet',
            'species' => 'dog',
            'sex' => 'male',
            'neutering_status' => 'unknown',
        ])->assertRedirect();

        $shelterPet = PetProfile::query()
            ->where('name', 'Wave5 Shelter Pet')
            ->where('service_domain', PetProfile::DOMAIN_SHELTER)
            ->firstOrFail();

        $this->actingAs($staff)
            ->get(route('shelter.pets.show', $shelterPet))
            ->assertOk()
            ->assertSee('Wave5 Shelter Pet')
            ->assertSee('Owner');

        $createClinic = $this->actingAs($client)->post(route('petcare.pets.store'), [
            'name' => 'Wave5 Clinic Pet',
            'species' => 'cat',
            'sex' => 'female',
            'neutering_status' => 'neutered',
        ]);

        $clinicPet = PetProfile::query()
            ->where('name', 'Wave5 Clinic Pet')
            ->where('service_domain', PetProfile::DOMAIN_PETCARE)
            ->firstOrFail();

        $createClinic->assertRedirect(route('petcare.pets.show', $clinicPet));

        $this->actingAs($staff)
            ->get(route('petcare.pets.show', $clinicPet))
            ->assertOk()
            ->assertSee('Wave5 Clinic Pet')
            ->assertDontSee('>Owner</dt>', false);
    }
}
