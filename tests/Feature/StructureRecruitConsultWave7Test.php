<?php

namespace Tests\Feature;

use App\Core\Accounts\Role;
use App\Core\Accounts\User;
use App\Core\Eloquent\MorphMap;
use App\Core\Extensions\Plugins\PluginRegistry;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Plugins\Consultations\Http\Controllers\ConsultationController;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\Recruitment\Http\Controllers\PublicRecruitmentApplicationController;
use Plugins\Recruitment\Http\Controllers\RecruitmentApplicationController;
use Plugins\Recruitment\Http\Controllers\RecruitmentBoardController;
use Plugins\Recruitment\Http\Controllers\RecruitmentRoleController;
use Plugins\Recruitment\Models\RecruitmentRole;
use Tests\Concerns\CreatesRecruitmentPersonas;
use Tests\TestCase;

class StructureRecruitConsultWave7Test extends TestCase
{
    use CreatesRecruitmentPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_recruitment_controllers_live_in_plugin_package(): void
    {
        foreach ([
            'recruitment.index' => RecruitmentBoardController::class,
            'recruitment.show' => RecruitmentBoardController::class,
            'recruitment.applications.index' => PublicRecruitmentApplicationController::class,
            'recruitment.applications.show' => PublicRecruitmentApplicationController::class,
            'recruitment.applications.withdraw' => PublicRecruitmentApplicationController::class,
            'recruitment.apply' => PublicRecruitmentApplicationController::class,
            'apes-cic.recruitment.index' => RecruitmentRoleController::class,
            'apes-cic.recruitment.store' => RecruitmentRoleController::class,
            'apes-cic.recruitment.show' => RecruitmentRoleController::class,
            'apes-cic.recruitment.update' => RecruitmentRoleController::class,
            'apes-cic.recruitment.publish' => RecruitmentRoleController::class,
            'apes-cic.recruitment.close' => RecruitmentRoleController::class,
            'apes-cic.recruitment.applications.index' => RecruitmentApplicationController::class,
            'apes-cic.recruitment.applications.show' => RecruitmentApplicationController::class,
            'apes-cic.recruitment.applications.update' => RecruitmentApplicationController::class,
        ] as $name => $controller) {
            $this->assertTrue(Route::has($name), "Missing route {$name}");
            $action = Route::getRoutes()->getByName($name)->getActionName();
            $this->assertStringContainsString($controller, $action);
        }

        $this->assertFileDoesNotExist(app_path('Http/Controllers/RecruitmentBoardController.php'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/PublicRecruitmentApplicationController.php'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/ApesCic/RecruitmentRoleController.php'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/ApesCic/RecruitmentApplicationController.php'));
        $this->assertFileDoesNotExist(app_path('Models/RecruitmentRole.php'));
        $this->assertFileDoesNotExist(app_path('Models/RecruitmentApplication.php'));
    }

    public function test_consultation_controller_lives_in_plugin_package(): void
    {
        foreach ([
            'petcare.consultations.index',
            'petcare.consultations.store',
            'petcare.consultations.show',
            'petcare.consultations.update',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Missing route {$name}");
            $action = Route::getRoutes()->getByName($name)->getActionName();
            $this->assertStringContainsString(ConsultationController::class, $action);
        }

        $this->assertFileDoesNotExist(app_path('Http/Controllers/PetCare/ConsultationController.php'));
        $this->assertFileDoesNotExist(app_path('Models/PetCareConsultation.php'));
    }

    public function test_live_recruitment_and_consultation_urls_remain(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $hr = $this->hrStaff();

        $this->get('/recruitment')->assertOk();
        $this->actingAs($hr)->get('/apes-cic/recruitment')->assertOk();
        $this->actingAs($hr)->get('/apes-cic/recruitment/applications')->assertOk();
        $this->actingAs($staff)->get('/petcare/consultations')->assertOk();
    }

    public function test_morph_aliases_point_at_plugin_models(): void
    {
        $this->assertSame(RecruitmentRole::class, MorphMap::aliases()['recruitment_role']);
        $this->assertSame(PetCareConsultation::class, MorphMap::aliases()['consultation']);
        $this->assertSame(MorphMap::aliases(), Relation::morphMap());
        $this->assertSame('recruitment_role', MorphMap::aliasFor(RecruitmentRole::class));
        $this->assertSame('consultation', MorphMap::aliasFor(PetCareConsultation::class));
    }

    public function test_plugin_manifests_are_registered(): void
    {
        $recruitment = app(PluginRegistry::class)->plugin('recruitment');
        $this->assertSame(['apes-cic'], $recruitment->compatibleModules);
        $this->assertSame('recruitment', $recruitment->translationNamespace);
        $this->assertCount(0, $recruitment->dependencies);

        $consultations = app(PluginRegistry::class)->plugin('consultations');
        $this->assertSame(['pet-care-clinic'], $consultations->compatibleModules);
        $this->assertSame('consultations', $consultations->translationNamespace);
        $this->assertCount(1, $consultations->dependencies);
        $this->assertSame('pet-profiles', $consultations->dependencies[0]->pluginSlug);
    }

    public function test_vacancy_model_remains_recruitment_role_not_spatie_role(): void
    {
        $this->assertSame('Plugins\\Recruitment\\Models\\RecruitmentRole', RecruitmentRole::class);
        $this->assertNotSame(Role::class, RecruitmentRole::class);
        $this->assertFalse(is_subclass_of(RecruitmentRole::class, \Spatie\Permission\Models\Role::class));
    }

    public function test_hr_staff_can_create_open_recruitment_role(): void
    {
        $staff = $this->hrStaff();

        $this->actingAs($staff)->post(route('apes-cic.recruitment.store'), [
            'title' => 'Wave7 volunteer',
            'summary' => 'Structure move coverage.',
            'description' => 'Help with day-to-day care.',
            'category' => 'volunteer',
            'location' => 'On site',
            'commitment' => 'Weekly',
        ])->assertRedirect();

        $role = RecruitmentRole::query()
            ->where('title', 'Wave7 volunteer')
            ->firstOrFail();

        $this->actingAs($staff)
            ->post(route('apes-cic.recruitment.publish', $role))
            ->assertRedirect();

        $this->get(route('recruitment.show', $role))
            ->assertOk()
            ->assertSee('Wave7 volunteer');
    }

    public function test_staff_can_create_petcare_consultation(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $owner = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create();

        $pet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'name' => 'Wave7 Pet',
            'species' => 'dog',
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);

        $this->actingAs($staff)->post(route('petcare.consultations.store'), [
            'pet_profile_id' => $pet->id,
            'subject' => 'Wave7 consult',
            'notes' => 'Structure move coverage.',
        ])->assertRedirect();

        $consultation = PetCareConsultation::query()
            ->where('subject', 'Wave7 consult')
            ->firstOrFail();

        $this->actingAs($staff)
            ->get(route('petcare.consultations.show', $consultation))
            ->assertOk()
            ->assertSee('Wave7 consult');
    }
}
