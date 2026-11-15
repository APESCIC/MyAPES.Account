<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Tickets\Models\SupportTicket;
use Tests\TestCase;

/**
 * Public "Open a ticket" login-first combined form (#662–#665).
 */
class PublicOpenTicketPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_guests_see_login_and_register_not_a_submit_form(): void
    {
        $this->get(route('tickets.public.index'))
            ->assertOk()
            ->assertSee('data-public-open-ticket-mode="guest"', false)
            ->assertSeeText('Open a ticket')
            ->assertSeeText('Sign in to open a ticket')
            ->assertSeeText('Public Register')
            ->assertSeeText('APES CIC')
            ->assertDontSee('data-public-open-ticket-form', false)
            ->assertSessionHas('url.intended', route('tickets.public.index'));
    }

    public function test_guest_menu_links_to_the_page(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('tickets.public.index').'"', false);
    }

    public function test_signed_in_public_users_see_the_combined_form(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $this->actingAs($user)
            ->get(route('tickets.public.index'))
            ->assertOk()
            ->assertSee('data-public-open-ticket-mode="form"', false)
            ->assertSee('data-public-open-ticket-form', false)
            ->assertSee('name="service"', false)
            ->assertSee('name="service_area"', false)
            ->assertSee('data-open-ticket-combined', false)
            ->assertSee('action="'.e(route('tickets.public.store')).'"', false);
    }

    public function test_combined_form_includes_bot_protection_hooks_when_enabled(): void
    {
        config(['myapes.bot_protection.enabled' => true]);
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $this->actingAs($user)
            ->get(route('tickets.public.index'))
            ->assertOk()
            ->assertSee('data-honeypot', false)
            ->assertSee('name="_form_started"', false);
    }

    public function test_staff_side_users_see_guidance_not_the_form(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)
            ->get(route('tickets.public.index'))
            ->assertOk()
            ->assertSee('data-public-open-ticket-mode="staff"', false)
            ->assertDontSee('data-public-open-ticket-form', false)
            ->assertSee('data-public-account-required', false);
    }

    public function test_login_from_start_url_returns_to_the_prefilled_combined_form(): void
    {
        $user = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create(['password' => 'correct-horse-battery']);
        $formUrl = route('tickets.public.index', [
            'service' => 'shelter-rescue',
            'area' => 'adoption',
        ]);

        $this->get(route('tickets.public.start', ['service' => 'shelter-rescue', 'area' => 'adoption', 'via' => 'login']))
            ->assertRedirect(route('public.login'))
            ->assertSessionHas('url.intended', $formUrl);

        $this->post(route('public.login.submit'), [
            'login' => $user->email,
            'password' => 'correct-horse-battery',
        ])->assertRedirect($formUrl);

        $this->get($formUrl)
            ->assertOk()
            ->assertSee('data-public-open-ticket-form', false)
            ->assertSee('value="shelter-rescue"', false);
    }

    public function test_register_path_keeps_the_chosen_service_and_area(): void
    {
        $this->get(route('tickets.public.start', ['service' => 'apes-cic', 'area' => 'it_systems', 'via' => 'register']))
            ->assertRedirect(route('public.register'))
            ->assertSessionHas('url.intended', route('tickets.public.index', [
                'service' => 'apes-cic',
                'area' => 'it_systems',
            ]));
    }

    public function test_signed_in_users_go_straight_to_the_prefilled_form(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $this->actingAs($user)
            ->get(route('tickets.public.start', ['service' => 'pet-care-clinic', 'area' => 'billing', 'via' => 'login']))
            ->assertRedirect(route('tickets.public.index', [
                'service' => 'pet-care-clinic',
                'area' => 'billing',
            ]));
    }

    public function test_submitting_creates_a_ticket_in_the_chosen_division(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $response = $this->actingAs($user)->post(route('tickets.public.store'), [
            'service' => 'shelter-rescue',
            'service_area' => 'adoption',
            'subject' => 'Need help with adoption',
            'priority' => 'medium',
            'description' => '<p>Please help with the next steps.</p>',
            '_form_started' => now()->subSeconds(5)->getTimestampMs(),
        ]);

        $ticket = SupportTicket::query()->latest('id')->first();
        $this->assertNotNull($ticket);
        $this->assertSame('shelter-rescue', $ticket->sub_core_key);
        $this->assertSame('adoption', $ticket->service_area);
        $this->assertSame($user->id, $ticket->user_id);
        $this->assertSame('open', $ticket->status);
        $this->assertNull($ticket->assigned_to);

        $response->assertRedirect(route('shelter.tickets.show', $ticket));
    }

    public function test_cic_and_pet_care_submit_into_their_systems(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $this->actingAs($user)->post(route('tickets.public.store'), [
            'service' => 'pet-care-clinic',
            'service_area' => 'appointment',
            'subject' => 'Book a slot',
            'priority' => 'low',
            'description' => '<p>Looking for an appointment.</p>',
            '_form_started' => now()->subSeconds(5)->getTimestampMs(),
        ])->assertRedirect();

        $this->assertSame(
            'pet-care-clinic',
            SupportTicket::query()->where('subject', 'Book a slot')->value('sub_core_key'),
        );
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

        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $this->actingAs($user)
            ->get(route('tickets.public.index'))
            ->assertOk()
            ->assertDontSee('value="shelter-rescue"', false);
    }

    public function test_invalid_service_on_submit_is_rejected(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $this->actingAs($user)
            ->from(route('tickets.public.index'))
            ->post(route('tickets.public.store'), [
                'service' => 'not-a-service',
                'service_area' => 'adoption',
                'subject' => 'Nope',
                'priority' => 'medium',
                'description' => '<p>Nope</p>',
                '_form_started' => now()->subSeconds(5)->getTimestampMs(),
            ])
            ->assertRedirect(route('tickets.public.index'))
            ->assertSessionHasErrors('service');
    }

    public function test_staff_cannot_submit_the_public_form(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)
            ->post(route('tickets.public.store'), [
                'service' => 'shelter-rescue',
                'service_area' => 'adoption',
                'subject' => 'Staff attempt',
                'priority' => 'medium',
                'description' => '<p>Should fail.</p>',
                '_form_started' => now()->subSeconds(5)->getTimestampMs(),
            ])
            ->assertForbidden();
    }

    public function test_an_invalid_prefill_is_ignored(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $this->actingAs($user)
            ->get(route('tickets.public.index', ['service' => '<script>', 'area' => 'adoption']))
            ->assertOk()
            ->assertDontSee('&lt;script&gt;', false)
            ->assertDontSee('<script>"', false);
    }
}
