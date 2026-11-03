<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use App\Services\ModuleSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Recruitment\Models\RecruitmentRole;
use Tests\TestCase;

/**
 * Public Roles Available board: Apply while signed out (#465).
 */
class PublicRolesApplyFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_signed_out_role_page_offers_sign_in_or_register_to_apply(): void
    {
        $role = $this->openRole();

        $this->get(route('recruitment.show', $role))
            ->assertOk()
            ->assertSee('id="apply"', false)
            ->assertSee('href="'.e(route('recruitment.apply.start', ['recruitmentRole' => $role, 'via' => 'login'])).'"', false)
            ->assertSee('href="'.e(route('recruitment.apply.start', ['recruitmentRole' => $role, 'via' => 'register'])).'"', false)
            ->assertDontSee('name="statement"', false);
    }

    public function test_apply_after_sign_in_returns_to_the_application_form(): void
    {
        $role = $this->openRole();
        $applicant = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create(['password' => 'correct-horse-battery']);
        $formUrl = route('recruitment.show', $role).'#apply';

        $this->get(route('recruitment.apply.start', ['recruitmentRole' => $role, 'via' => 'login']))
            ->assertRedirect(route('public.login'))
            ->assertSessionHas('url.intended', $formUrl);

        $this->post(route('public.login.submit'), [
            'login' => $applicant->email,
            'password' => 'correct-horse-battery',
        ])->assertRedirect($formUrl);

        $this->get(route('recruitment.show', $role))
            ->assertOk()
            ->assertSee('name="statement"', false);
    }

    public function test_apply_after_registering_returns_to_the_form_once_onboarding_is_done(): void
    {
        $role = $this->openRole();
        $formUrl = route('recruitment.show', $role).'#apply';

        $this->get(route('recruitment.apply.start', ['recruitmentRole' => $role, 'via' => 'register']))
            ->assertRedirect(route('public.register'))
            ->assertSessionHas('url.intended', $formUrl);

        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create(['onboarding_completed_at' => null]);

        $this->actingAs($user)
            ->withSession(['url.intended' => $formUrl])
            ->put(route('onboarding.update'), [
                'address_line_1' => '1 Test Street',
                'town_city' => 'London',
                'postcode' => 'SW1A 1AA',
                'mobile_number' => '+447400123456',
                'services' => ['apes-cic'],
                'contact_preferences_confirmed' => '1',
            ])
            ->assertRedirect($formUrl);
    }

    public function test_signed_in_users_go_straight_to_the_form(): void
    {
        $role = $this->openRole();
        $applicant = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $this->actingAs($applicant)
            ->get(route('recruitment.apply.start', ['recruitmentRole' => $role, 'via' => 'login']))
            ->assertRedirect(route('recruitment.show', $role).'#apply');
    }

    public function test_apply_start_refuses_closed_roles_and_a_hidden_board(): void
    {
        $closed = RecruitmentRole::factory()->closed()->create(['created_by' => User::factory()->create()->id]);
        $this->get(route('recruitment.apply.start', ['recruitmentRole' => $closed, 'via' => 'login']))->assertNotFound();

        $open = $this->openRole();
        $this->mock(ModuleSettingsService::class, function ($mock): void {
            $mock->shouldReceive('recruitmentPublicBoardEnabled')->andReturnFalse();
        });
        $this->get(route('recruitment.apply.start', ['recruitmentRole' => $open, 'via' => 'login']))->assertNotFound();
    }

    private function openRole(): RecruitmentRole
    {
        return RecruitmentRole::factory()->open()->category('volunteer')->create([
            'created_by' => User::factory()->create()->id,
            'title' => 'Apply flow volunteer',
        ]);
    }
}
