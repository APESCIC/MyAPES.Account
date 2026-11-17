<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\BotProtection\BotProtection;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Plugins\Tickets\Embed\EmbedToken;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Models\TicketEmbedForm;
use Plugins\Tickets\Notifications\GuestTicketConfirmationNotification;
use Tests\TestCase;

/**
 * Embeddable guest open-a-ticket form (#682–#693).
 */
class TicketEmbedFormTest extends TestCase
{
    use RefreshDatabase;

    private TicketEmbedForm $form;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        config(['myapes.bot_protection.enabled' => false]);

        $this->form = TicketEmbedForm::query()->create([
            'name' => 'APES website',
            'public_key' => TicketEmbedForm::newPublicKey(),
            'signing_secret' => TicketEmbedForm::newSigningSecret(),
            'is_active' => true,
            'enabled_services' => ['apes-cic', 'shelter-rescue', 'pet-care-clinic'],
            'allowed_origins' => ['https://www.apes.org.uk', 'https://myapes-account.test'],
            'spam_score_threshold' => null,
            'confirmation_text' => null,
        ]);
    }

    public function test_open_a_ticket_remains_login_first_for_guests(): void
    {
        $this->get(route('tickets.public.index'))
            ->assertOk()
            ->assertSee('data-public-open-ticket-mode="guest"', false)
            ->assertDontSee('data-public-open-ticket-form', false);
    }

    public function test_staff_cannot_use_the_embed_as_themselves(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $token = EmbedToken::mint($this->form, 'https://www.apes.org.uk');

        $this->actingAs($staff)
            ->withHeader('Origin', 'https://www.apes.org.uk')
            ->get(route('tickets.embed.show', [
                'publicKey' => $this->form->public_key,
                'token' => $token,
                'parent_origin' => 'https://www.apes.org.uk',
            ]))
            ->assertForbidden();
    }

    public function test_allowed_origin_can_mint_a_token_and_see_the_form(): void
    {
        $this->withHeader('Origin', 'https://www.apes.org.uk')
            ->getJson(route('tickets.embed.token', $this->form->public_key))
            ->assertOk()
            ->assertJsonStructure(['token', 'form_url', 'expires_in']);

        $token = EmbedToken::mint($this->form, 'https://www.apes.org.uk');

        $this->withHeader('Origin', 'https://www.apes.org.uk')
            ->get(route('tickets.embed.show', [
                'publicKey' => $this->form->public_key,
                'token' => $token,
                'parent_origin' => 'https://www.apes.org.uk',
            ]))
            ->assertOk()
            ->assertSee('data-myapes-embed-form', false)
            ->assertHeader('Content-Security-Policy')
            ->assertSee('https://www.apes.org.uk', false);
    }

    public function test_banned_origin_cannot_mint_a_token(): void
    {
        $this->withHeader('Origin', 'https://evil.example')
            ->getJson(route('tickets.embed.token', $this->form->public_key))
            ->assertForbidden();
    }

    public function test_guest_can_submit_and_verify_email(): void
    {
        Notification::fake();
        $token = EmbedToken::mint($this->form, 'https://www.apes.org.uk');

        $response = $this->withHeader('Origin', 'https://www.apes.org.uk')
            ->post(route('tickets.embed.store', $this->form->public_key), [
                'embed_token' => $token,
                'parent_origin' => 'https://www.apes.org.uk',
                'name' => 'Guest User',
                'email' => 'guest@example.com',
                'service' => 'shelter-rescue',
                'service_area' => 'adoption',
                'subject' => 'Need help',
                'details' => 'Please call me about adoption.',
                'consent' => '1',
                BotProtection::HONEYPOT_FIELD => '',
                BotProtection::STARTED_FIELD => Crypt::encryptString((string) now()->subSeconds(5)->getTimestamp()),
            ]);

        $response->assertOk()->assertSeeText('Check your email');

        $ticket = SupportTicket::query()->first();
        $this->assertNotNull($ticket);
        $this->assertNull($ticket->user_id);
        $this->assertSame('guest@example.com', $ticket->guest_email);
        $this->assertNull($ticket->guest_email_verified_at);
        $this->assertNull($ticket->staff_notified_at);

        Notification::assertSentOnDemand(GuestTicketConfirmationNotification::class);

        $plain = null;
        Notification::assertSentOnDemand(GuestTicketConfirmationNotification::class, function ($notification) use (&$plain) {
            $plain = $notification->verifyToken;

            return $notification->verifyToken !== null;
        });

        $matched = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'email' => 'guest@example.com',
        ]);

        $this->get(route('tickets.embed.verify', ['token' => $plain]))
            ->assertOk()
            ->assertSeeText('Email confirmed');

        $ticket->refresh();
        $this->assertNotNull($ticket->guest_email_verified_at);
        $this->assertSame($matched->id, $ticket->user_id);
        $this->assertNotNull($ticket->staff_notified_at);
    }

    public function test_disabled_form_shows_inactive_message(): void
    {
        $this->form->forceFill(['is_active' => false])->save();
        $token = EmbedToken::mint($this->form, 'https://www.apes.org.uk');

        $this->withHeader('Origin', 'https://www.apes.org.uk')
            ->get(route('tickets.embed.show', [
                'publicKey' => $this->form->public_key,
                'token' => $token,
                'parent_origin' => 'https://www.apes.org.uk',
            ]))
            ->assertForbidden()
            ->assertSeeText('not active');
    }

    public function test_admin_can_manage_embed_forms_and_non_admins_get_403(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $public = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $this->actingAs($public)
            ->get(route('admin.tickets.embed-forms.index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.tickets.embed-forms.index'))
            ->assertOk()
            ->assertSeeText('Embed forms');

        $this->actingAs($admin)
            ->post(route('admin.tickets.embed-forms.store'), [
                'name' => 'Shelter site',
                'is_active' => '1',
                'enabled_services' => ['shelter-rescue'],
                'allowed_origins_text' => "https://myapes-account.test\nhttps://www.apes.org.uk",
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ticket_embed_forms', ['name' => 'Shelter site']);
    }

    public function test_non_embed_pages_keep_frame_ancestors_self(): void
    {
        $policy = $this->get(route('public.login'))
            ->assertOk()
            ->headers
            ->get('Content-Security-Policy')
            ?? $this->get(route('public.login'))->headers->get('Content-Security-Policy-Report-Only');

        $this->assertNotNull($policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
    }

    public function test_loader_script_is_public(): void
    {
        $this->get(route('tickets.embed.loader'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')
            ->assertSee('myapes-ticket-embed', false);
    }
}
