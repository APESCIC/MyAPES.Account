<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailStore;
use App\Notifications\EmailReplyNotPostedNotification;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Plugins\Cases\Models\CaseUpdate;
use Plugins\Cases\Notifications\ApesCicCaseUpdatedNotification;
use Plugins\Cases\Notifications\ShelterCaseUpdatedNotification;
use Plugins\Tickets\Models\SupportTicketMessage;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Tests\TestCase;

/**
 * Posting a reply by email (#582).
 */
class PostEmailReplyTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Storage::fake('private');
        Notification::fake();
        $this->enableEmailReplies();
        $this->owner = User::factory()->create(['email' => 'owner@example.org']);
        $this->staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create(['email' => 'staff@example.org']);
    }

    public function test_an_owner_reply_appears_on_the_ticket_marked_by_email_and_notifies_staff(): void
    {
        $ticket = $this->ticketFor($this->owner);

        $email = $this->receive(['TO' => $this->replyAddressFor('T', $ticket->id, $this->owner), 'BODY' => 'It is working again.']);

        $this->assertSame(InboundEmail::STATUS_POSTED, $email->status);
        $message = SupportTicketMessage::query()->sole();
        $this->assertSame($this->owner->id, $message->user_id);
        $this->assertTrue($message->via_email);
        $this->assertFalse($message->is_staff_note);
        $this->assertStringContainsString('It is working again.', (string) $message->message);
        Notification::assertSentTo($this->staff, TicketUpdatedNotification::class);
        Notification::assertNotSentTo($this->owner, TicketUpdatedNotification::class);

        $this->actingAs($this->owner)
            ->get(route('apes-cic.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('It is working again.')
            ->assertSee('data-via-email', false)
            ->assertSee(__('mail.reply_by_email.badge'));
    }

    public function test_a_staff_reply_is_always_public_and_reaches_the_owner(): void
    {
        $ticket = $this->ticketFor($this->owner);

        $this->receive([
            'FROM' => 'staff@example.org',
            'TO' => $this->replyAddressFor('T', $ticket->id, $this->staff),
            'SUBJECT' => 'Re: internal note',
            'BODY' => 'Internal: we have ordered the part.',
        ]);

        $message = SupportTicketMessage::query()->sole();
        $this->assertFalse($message->is_staff_note);
        Notification::assertSentTo($this->owner, TicketUpdatedNotification::class);
    }

    public function test_case_replies_post_as_public_updates(): void
    {
        $cic = $this->apesCicCaseFor($this->owner);
        $shelter = $this->shelterCaseFor($this->owner);

        $this->receive(['TO' => $this->replyAddressFor('C', $cic->id, $this->owner), 'BODY' => 'CIC reply']);
        $this->receive([
            'FROM' => 'staff@example.org',
            'TO' => $this->replyAddressFor('C', $shelter->id, $this->staff),
            'BODY' => 'Shelter reply',
        ]);

        $updates = CaseUpdate::query()->orderBy('id')->get();
        $this->assertCount(2, $updates);
        $this->assertSame([CaseUpdate::VISIBILITY_PUBLIC, CaseUpdate::VISIBILITY_PUBLIC], $updates->pluck('visibility')->all());
        $this->assertSame([true, true], $updates->pluck('via_email')->all());
        Notification::assertSentTo($this->staff, ApesCicCaseUpdatedNotification::class);
        Notification::assertSentTo($this->owner, ShelterCaseUpdatedNotification::class);
    }

    public function test_a_reply_to_a_closed_ticket_is_not_posted_and_gets_one_notice(): void
    {
        $ticket = $this->ticketFor($this->owner, ['status' => 'closed', 'closed_at' => now()]);

        $first = $this->receive(['TO' => $this->replyAddressFor('T', $ticket->id, $this->owner), 'BODY' => 'Hello?']);
        $second = $this->receive(['TO' => $this->replyAddressFor('T', $ticket->id, $this->owner), 'BODY' => 'Hello again?']);

        $this->assertSame([InboundEmail::STATUS_REJECTED, 'closed', true], [$first->status, $first->reason, $first->notice_sent]);
        $this->assertSame([InboundEmail::STATUS_REJECTED, 'closed', false], [$second->status, $second->reason, $second->notice_sent]);
        $this->assertSame(0, SupportTicketMessage::query()->count());
        Notification::assertSentToTimes($this->owner, EmailReplyNotPostedNotification::class, 1);
        Notification::assertNotSentTo($this->staff, TicketUpdatedNotification::class);
    }

    public function test_a_reply_to_a_closed_case_is_not_posted(): void
    {
        $case = $this->apesCicCaseFor($this->owner, ['status' => 'closed']);

        $email = $this->receive(['TO' => $this->replyAddressFor('C', $case->id, $this->owner)]);

        $this->assertSame('closed', $email->reason);
        $this->assertSame(0, CaseUpdate::query()->count());
        Notification::assertSentTo($this->owner, EmailReplyNotPostedNotification::class);
    }

    public function test_the_notice_is_marked_as_an_automatic_reply(): void
    {
        $mail = (new EmailReplyNotPostedNotification('Ticket #5', url('/apes-cic/tickets/5')))->toMail($this->owner);

        $this->assertSame('Your email reply to Ticket #5 was not added', $mail->subject);
        $this->assertNotEmpty($mail->callbacks);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function receive(array $values): InboundEmail
    {
        $email = app(InboundMailStore::class)->store($this->fixture('plain-reply', $values));
        $this->assertNotNull($email);

        return $email->fresh();
    }
}
