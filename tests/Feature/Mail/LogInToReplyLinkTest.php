<?php

namespace Tests\Feature\Mail;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Cases\Notifications\ApesCicCaseUpdatedNotification;
use Plugins\Cases\Notifications\ShelterCaseUpdatedNotification;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Tests\TestCase;

/**
 * "Log in to reply" links in ticket and case emails (#468).
 */
class LogInToReplyLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_ticket_and_case_emails_carry_a_signed_log_in_to_reply_button(): void
    {
        $owner = User::factory()->create();
        $ticket = $this->ticket($owner);
        $apesCicCase = $this->apesCicCase($owner);
        $shelterCase = $this->shelterCase($owner);

        $mails = [
            route('apes-cic.tickets.show', $ticket) => (new TicketUpdatedNotification($ticket, $owner, 'updated', 'apes-cic', 'apes-cic.tickets.show', 'APES CIC'))->toMail($owner),
            route('apes-cic.cases.show', $apesCicCase) => (new ApesCicCaseUpdatedNotification($apesCicCase, $owner, 'updated', 'apes-cic', 'apes-cic.cases.show'))->toMail($owner),
            route('shelter.cases.show', $shelterCase) => (new ShelterCaseUpdatedNotification($shelterCase, $owner, 'updated'))->toMail($owner),
        ];

        foreach ($mails as $itemUrl => $mail) {
            $this->assertSame('Log in to reply', $mail->actionText);
            $this->assertReplyLinkTo($mail, $itemUrl, 'public');
        }
    }

    public function test_staff_recipients_are_sent_through_staff_sign_in(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $ticket = $this->ticket($owner);

        $mail = (new TicketUpdatedNotification($ticket, $owner, 'updated', 'apes-cic', 'apes-cic.tickets.show', 'APES CIC'))->toMail($staff);

        $this->assertReplyLinkTo($mail, route('apes-cic.tickets.show', $ticket), 'staff');
        $this->get($mail->actionUrl)->assertRedirect(route('staff.login'));
    }

    public function test_signed_out_readers_return_to_the_item_after_signing_in(): void
    {
        $owner = User::factory()->create(['password' => 'correct-horse-battery']);
        $ticket = $this->ticket($owner);
        $itemUrl = route('apes-cic.tickets.show', $ticket);
        $mail = (new TicketUpdatedNotification($ticket, $owner, 'updated', 'apes-cic', 'apes-cic.tickets.show', 'APES CIC'))->toMail($owner);

        $this->get($mail->actionUrl)
            ->assertRedirect(route('public.login'))
            ->assertSessionHas('url.intended', $itemUrl);

        $this->post(route('public.login.submit'), [
            'login' => $owner->email,
            'password' => 'correct-horse-battery',
        ])->assertRedirect($itemUrl);
    }

    public function test_signed_in_readers_go_straight_to_the_item(): void
    {
        $owner = User::factory()->create();
        $ticket = $this->ticket($owner);
        $mail = (new TicketUpdatedNotification($ticket, $owner, 'updated', 'apes-cic', 'apes-cic.tickets.show', 'APES CIC'))->toMail($owner);

        $this->actingAs($owner)
            ->get($mail->actionUrl)
            ->assertRedirect(route('apes-cic.tickets.show', $ticket));
    }

    public function test_a_tampered_link_is_refused(): void
    {
        $owner = User::factory()->create();
        $ticket = $this->ticket($owner);
        $mail = (new TicketUpdatedNotification($ticket, $owner, 'updated', 'apes-cic', 'apes-cic.tickets.show', 'APES CIC'))->toMail($owner);

        $tampered = str_replace(urlencode('/apes-cic/tickets/'), urlencode('//evil.example/'), (string) $mail->actionUrl);
        $this->assertNotSame($mail->actionUrl, $tampered);

        $this->get($tampered)->assertForbidden();
    }

    private function assertReplyLinkTo(MailMessage $mail, string $itemUrl, string $via): void
    {
        $url = (string) $mail->actionUrl;
        $this->assertStringStartsWith(route('mail.reply'), $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame(parse_url($itemUrl, PHP_URL_PATH), $query['to'] ?? null);
        $this->assertSame($via, $query['via'] ?? null);
        $this->assertArrayHasKey('signature', $query);
    }

    private function ticket(User $owner): SupportTicket
    {
        return SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => 'Reply link check',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Ticket used to check the reply link.',
        ]);
    }

    private function apesCicCase(User $owner): ShelterCase
    {
        return ShelterCase::query()->create([
            'sub_core_key' => ShelterCase::SUB_CORE_APES_CIC,
            'user_id' => $owner->id,
            'category' => 'general',
            'priority' => 'medium',
            'status' => 'open',
            'title' => 'Reply link case',
            'details' => 'Case used to check the reply link.',
        ]);
    }

    private function shelterCase(User $owner): ShelterCase
    {
        $pet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_SHELTER,
            'name' => 'Reply pet',
            'species' => 'dog',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);

        return ShelterCase::query()->create([
            'sub_core_key' => ShelterCase::SUB_CORE_SHELTER_RESCUE,
            'pet_profile_id' => $pet->id,
            'user_id' => $owner->id,
            'case_type' => 'surrender',
            'status' => 'in_review',
            'title' => 'Shelter reply case',
            'details' => 'Case used to check the reply link.',
        ]);
    }
}
