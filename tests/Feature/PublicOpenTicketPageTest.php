<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public "Open a ticket" page (#466).
 */
class PublicOpenTicketPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_page_loads_signed_out_and_lists_each_service_with_its_areas(): void
    {
        $this->get(route('tickets.public.index'))
            ->assertOk()
            ->assertSee('data-public-open-ticket', false)
            ->assertSeeText('Open a ticket')
            ->assertSeeText('APES CIC')
            ->assertSeeText('APES Shelter and Rescue')
            ->assertSeeText('APES Pet Care Clinic')
            ->assertSeeText('Web Development & Digital')
            ->assertSeeText('Adoption')
            ->assertSeeText('Appointment')
            ->assertSee('href="'.e(route('tickets.public.start', ['service' => 'apes-cic', 'area' => 'it_systems', 'via' => 'login'])).'"', false)
            ->assertSee('href="'.e(route('tickets.public.start', ['service' => 'shelter-rescue', 'area' => 'adoption', 'via' => 'register'])).'"', false);
    }

    public function test_guest_menu_links_to_the_page(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('tickets.public.index').'"', false);
    }

    public function test_opening_a_ticket_needs_an_account_and_returns_to_the_prefilled_form(): void
    {
        $user = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create(['password' => 'correct-horse-battery']);
        $user->serviceSelections()->firstOrCreate(['sub_core_key' => 'shelter-rescue']);
        $formUrl = route('shelter.tickets.index', ['service_area' => 'adoption']).'#create';

        $this->get(route('tickets.public.start', ['service' => 'shelter-rescue', 'area' => 'adoption', 'via' => 'login']))
            ->assertRedirect(route('public.login'))
            ->assertSessionHas('url.intended', $formUrl);

        $this->post(route('public.login.submit'), [
            'login' => $user->email,
            'password' => 'correct-horse-battery',
        ])->assertRedirect($formUrl);

        $this->get(route('shelter.tickets.index', ['service_area' => 'adoption']))
            ->assertOk()
            ->assertSee('<option value="adoption" selected>', false);
    }

    public function test_register_path_keeps_the_chosen_service_and_area(): void
    {
        $this->get(route('tickets.public.start', ['service' => 'apes-cic', 'area' => 'it_systems', 'via' => 'register']))
            ->assertRedirect(route('public.register'))
            ->assertSessionHas('url.intended', route('apes-cic.tickets.index', ['service_area' => 'it_systems']).'#create');
    }

    public function test_signed_in_users_go_straight_to_the_form(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $this->actingAs($user)
            ->get(route('tickets.public.start', ['service' => 'pet-care-clinic', 'area' => 'billing', 'via' => 'login']))
            ->assertRedirect(route('petcare.tickets.index', ['service_area' => 'billing']).'#create');
    }

    public function test_unknown_service_or_area_and_disabled_tickets_are_refused(): void
    {
        $this->get(route('tickets.public.start', ['service' => 'apes-cic', 'area' => 'not_an_area', 'via' => 'login']))->assertNotFound();
        $this->get('/open-a-ticket/unknown/adoption/start')->assertNotFound();

        ModuleInstallation::query()
            ->where('sub_core_key', 'shelter-rescue')
            ->where('module_key', 'tickets')
            ->update(['enabled' => false]);

        $this->get(route('tickets.public.start', ['service' => 'shelter-rescue', 'area' => 'adoption', 'via' => 'login']))->assertNotFound();
        $this->get(route('tickets.public.index'))
            ->assertOk()
            ->assertDontSee('href="'.e(route('tickets.public.start', ['service' => 'shelter-rescue', 'area' => 'adoption', 'via' => 'login'])).'"', false);
    }

    public function test_an_invalid_prefill_is_ignored(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $this->actingAs($user)
            ->get(route('apes-cic.tickets.index', ['service_area' => '<script>']))
            ->assertOk()
            ->assertDontSee('&lt;script&gt;', false)
            ->assertDontSee('<script>"', false);
    }
}
