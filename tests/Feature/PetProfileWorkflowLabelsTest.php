<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\PetProfiles\Models\PetProfile;
use Tests\TestCase;

class PetProfileWorkflowLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_pet_screens_show_title_case_sex_and_neutering_status(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $pet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_SHELTER,
            'name' => 'Label pet',
            'species' => 'Bearded dragon',
            'sex' => 'male',
            'neutering_status' => 'not_neutered',
        ]);

        $this->actingAs($owner)
            ->get(route('shelter.pets.index'))
            ->assertOk()
            ->assertSee('<td>Male</td>', false)
            ->assertSee('<option value="not_neutered">Not Neutered</option>', false)
            ->assertDontSee('>not_neutered<', false);

        $html = $this->actingAs($staff)
            ->get(route('shelter.pets.show', $pet))
            ->assertOk()
            ->assertSeeText('| Male | Not Neutered')
            ->assertSee('<option value="male" selected>Male</option>', false)
            ->assertSee('<option value="not_neutered" selected>Not Neutered</option>', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/>(male|female|unknown|neutered|not_neutered)</', $html);
        $this->assertStringNotContainsString('| male |', $html);
    }
}
