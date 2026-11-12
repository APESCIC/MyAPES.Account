<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailStore;
use App\Notifications\EmailReplyNotPostedNotification;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Models\SupportTicketMessage;
use Tests\TestCase;

/**
 * Attach to a ticket or case, and the unmatched notice (#749).
 */
class InboundTriageTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private SupportTicket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Storage::fake('private');
        Notification::fake();
        $this->enableEmailReplies();
        $this->owner = User::factory()->create(['email' => 'owner@example.org']);
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $this->ticket = $this->ticketFor($this->owner);
    }

    public function test_a_known_sender_gets_at_most_one_unmatched_notice_an_hour(): void
    {
        $first = $this->receive(['SUBJECT' => 'Hello', 'BODY' => 'The tag was lost.']);
        $second = $this->receive(['SUBJECT' => 'Hello again', 'BODY' => 'Still lost.']);

        $this->assertSame([InboundEmail::STATUS_QUARANTINED, 'unmatched', true, $this->owner->id], [$first->status, $first->reason, $first->notice_sent, $first->user_id]);
        $this->assertFalse($second->notice_sent);
        Notification::assertSentToTimes($this->owner, EmailReplyNotPostedNotification::class, 1);
    }

    public function test_the_unmatched_notice_is_an_automatic_reply(): void
    {
        $mail = (new EmailReplyNotPostedNotification(__('mail.reply_not_posted.unmatched_reference'), route('dashboard'), EmailReplyNotPostedNotification::REASON_UNMATCHED))->toMail($this->owner);

        $this->assertStringContainsString(__('mail.reply_not_posted.unmatched_reference'), $mail->subject);
        $this->assertContains(__('mail.reply_not_posted.line_unmatched'), $mail->introLines);
        $this->assertNotEmpty($mail->callbacks);
    }

    public function test_unknown_or_unauthenticated_senders_get_no_reply(): void
    {
        $unknown = $this->receive(['FROM' => 'stranger@example.org', 'SUBJECT' => 'Hello']);
        $spoofed = $this->receive(['SUBJECT' => 'Hello', 'AUTH' => 'mx.example.test; spf=fail smtp.mailfrom=example.org; dkim=fail; dmarc=fail']);

        $this->assertSame('unmatched', $unknown->reason);
        $this->assertFalse($unknown->notice_sent);
        $this->assertFalse($spoofed->notice_sent);
        Notification::assertNothingSent();
    }

    public function test_an_admin_attaches_a_held_reply_to_a_ticket(): void
    {
        $email = $this->receive(['SUBJECT' => 'Hello', 'BODY' => 'This belongs on my ticket.']);

        $this->actingAs($this->admin)
            ->post(route('admin.emails.inbound.attach', $email), ['record' => 'T'.$this->ticket->id])
            ->assertRedirect(route('admin.emails.inbound.show', $email))
            ->assertSessionHas('status');

        $email->refresh();
        $message = SupportTicketMessage::query()->sole();
        $this->assertSame(InboundEmail::STATUS_POSTED, $email->status);
        $this->assertSame(['T', $this->ticket->id, $message->id], [$email->record_prefix, $email->record_id, (int) $email->posted_id]);
        $this->assertSame($this->owner->id, $message->user_id);
        $this->assertTrue($message->via_email);
        $this->assertStringContainsString('This belongs on my ticket.', (string) $message->message);

        $audit = AuditLog::query()->where('event', 'admin.emails.inbound_attached')->sole();
        $this->assertSame(['record' => 'T'.$this->ticket->id, 'account_id' => $this->owner->id], $audit->context);
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertStringNotContainsString('This belongs on my ticket.', json_encode($audit->toArray()));
    }

    public function test_an_unknown_sender_needs_an_account_chosen(): void
    {
        $email = $this->receive(['FROM' => 'stranger@example.org', 'SUBJECT' => 'Hello']);

        $this->actingAs($this->admin)
            ->post(route('admin.emails.inbound.attach', $email), ['record' => 'T'.$this->ticket->id])
            ->assertSessionHasErrors('account');
        $this->assertSame(0, SupportTicketMessage::query()->count());

        $this->actingAs($this->admin)
            ->post(route('admin.emails.inbound.attach', $email), ['record' => 'T'.$this->ticket->id, 'account' => 'owner@example.org'])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->owner->id, SupportTicketMessage::query()->sole()->user_id);
    }

    public function test_closed_records_and_posted_emails_cannot_be_attached_to(): void
    {
        $closed = $this->ticketFor($this->owner, ['status' => 'closed', 'closed_at' => now()]);
        $email = $this->receive(['SUBJECT' => 'Hello']);

        $this->actingAs($this->admin)
            ->post(route('admin.emails.inbound.attach', $email), ['record' => 'T'.$closed->id])
            ->assertSessionHasErrors(['record' => __('admin.emails.inbound.attach_closed')]);
        $this->actingAs($this->admin)
            ->post(route('admin.emails.inbound.attach', $email), ['record' => 'T999999'])
            ->assertSessionHasErrors(['record' => __('admin.emails.inbound.attach_record_missing')]);

        $posted = $this->receive(['TO' => $this->replyAddressFor('T', $this->ticket->id, $this->owner)]);
        $this->actingAs($this->admin)
            ->post(route('admin.emails.inbound.attach', $posted), ['record' => 'T'.$this->ticket->id])
            ->assertSessionHasErrors(['record' => __('admin.emails.inbound.attach_not_quarantined')]);
        $this->assertSame(1, SupportTicketMessage::query()->count());
    }

    public function test_only_email_admins_can_attach(): void
    {
        $email = $this->receive(['SUBJECT' => 'Hello']);
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)
            ->post(route('admin.emails.inbound.attach', $email), ['record' => 'T'.$this->ticket->id])
            ->assertForbidden();
        $this->assertSame(0, SupportTicketMessage::query()->count());
    }

    public function test_the_attach_form_shows_only_for_held_emails(): void
    {
        $held = $this->receive(['SUBJECT' => 'Hello']);

        $this->actingAs($this->admin)->get(route('admin.emails.inbound.show', $held))->assertSee('data-inbound-attach', false);
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
