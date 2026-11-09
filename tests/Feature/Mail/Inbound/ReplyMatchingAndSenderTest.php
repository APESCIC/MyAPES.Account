<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailStore;
use App\Core\Mail\Inbound\MailReplyThread;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Models\SupportTicketMessage;
use Tests\TestCase;

/**
 * Matching a reply to a ticket or case, and verifying the sender (#580).
 */
class ReplyMatchingAndSenderTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private User $owner;

    private SupportTicket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Storage::fake('private');
        Notification::fake();
        $this->enableEmailReplies();
        $this->owner = User::factory()->create(['email' => 'owner@example.org']);
        $this->ticket = $this->ticketFor($this->owner);
    }

    public function test_a_token_reply_from_the_owner_is_posted(): void
    {
        $email = $this->receive(['TO' => $this->replyAddressFor('T', $this->ticket->id, $this->owner)]);

        $this->assertPosted($email);
        $this->assertSame('pass', $email->spf);
        $this->assertSame($this->owner->id, $email->user_id);
        $this->assertSame(['T', $this->ticket->id, 'tickets', 'apes-cic'], [$email->record_prefix, $email->record_id, $email->plugin, $email->module_slug]);
    }

    public function test_the_subject_tag_matches_when_there_is_no_token(): void
    {
        $email = $this->receive(['SUBJECT' => 'Re: APES CIC ticket #'.$this->ticket->id.' updated [MYAPES-T-'.$this->ticket->id.']']);

        $this->assertPosted($email);
    }

    public function test_in_reply_to_matches_a_stored_message_id(): void
    {
        MailReplyThread::query()->create([
            'message_id' => 'myapes.t'.$this->ticket->id.'.abc@example.test',
            'record_prefix' => 'T',
            'record_id' => $this->ticket->id,
            'user_id' => $this->owner->id,
            'created_at' => now(),
        ]);

        $email = $this->receive(['IN_REPLY_TO' => '<myapes.t'.$this->ticket->id.'.abc@example.test>']);

        $this->assertPosted($email);
    }

    public function test_spoofed_mail_is_quarantined(): void
    {
        $failing = 'mx.example.test; spf=fail smtp.mailfrom=example.org; dkim=fail header.d=example.org; dmarc=fail';
        $dmarcFail = 'mx.example.test; spf=pass smtp.mailfrom=example.org; dkim=none; dmarc=fail header.from=example.org';

        $this->assertQuarantined($this->receive(['AUTH' => $failing, 'TO' => $this->replyAddressFor('T', $this->ticket->id, $this->owner)]), 'authentication_failed');
        $this->assertQuarantined($this->receive(['AUTH' => $dmarcFail, 'TO' => $this->replyAddressFor('T', $this->ticket->id, $this->owner)]), 'authentication_failed');
    }

    public function test_results_from_another_server_are_not_trusted(): void
    {
        $email = $this->receive([
            'AUTH' => 'attacker.example; spf=pass; dkim=pass; dmarc=pass',
            'TO' => $this->replyAddressFor('T', $this->ticket->id, $this->owner),
        ]);

        $this->assertQuarantined($email, 'unauthenticated');
    }

    public function test_unknown_senders_are_quarantined(): void
    {
        $email = $this->receive([
            'FROM' => 'stranger@example.org',
            'TO' => $this->replyAddressFor('T', $this->ticket->id, $this->owner),
        ]);

        $this->assertQuarantined($email, 'unknown_sender');
        $this->assertNull($email->user_id);
    }

    public function test_a_token_used_by_someone_else_is_quarantined(): void
    {
        User::factory()->create(['email' => 'other@example.org']);

        $email = $this->receive([
            'FROM' => 'other@example.org',
            'TO' => $this->replyAddressFor('T', $this->ticket->id, $this->owner),
        ]);

        $this->assertQuarantined($email, 'wrong_sender');
    }

    public function test_a_subject_tag_on_someone_elses_ticket_is_quarantined(): void
    {
        User::factory()->create(['email' => 'other@example.org']);

        $email = $this->receive(['FROM' => 'other@example.org', 'SUBJECT' => 'Re: [MYAPES-T-'.$this->ticket->id.']']);

        $this->assertQuarantined($email, 'not_permitted');
    }

    public function test_suspended_accounts_are_rejected(): void
    {
        $this->owner->forceFill(['suspended_at' => now()])->save();

        $email = $this->receive(['TO' => $this->replyAddressFor('T', $this->ticket->id, $this->owner)]);

        $this->assertSame(InboundEmail::STATUS_REJECTED, $email->status);
        $this->assertSame('suspended', $email->reason);
        $this->assertSame(0, SupportTicketMessage::query()->count());
    }

    public function test_unmatched_and_missing_records_are_quarantined(): void
    {
        $this->assertQuarantined($this->receive(['SUBJECT' => 'Hello']), 'unmatched');
        $this->assertQuarantined($this->receive(['SUBJECT' => 'Re: [MYAPES-T-999999]']), 'record_not_found');
        $this->assertQuarantined($this->receive(['SUBJECT' => 'Re: [MYAPES-Z-1]']), 'unmatched');
    }

    public function test_eligible_staff_can_reply_by_token(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create(['email' => 'staff@example.org']);

        $email = $this->receive([
            'FROM' => 'staff@example.org',
            'TO' => $this->replyAddressFor('T', $this->ticket->id, $staff),
        ]);

        $this->assertPosted($email);
        $this->assertFalse(SupportTicketMessage::query()->sole()->is_staff_note);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function receive(array $values = []): InboundEmail
    {
        $email = app(InboundMailStore::class)->store($this->fixture('plain-reply', $values));
        $this->assertNotNull($email);

        return $email->fresh();
    }

    private function assertPosted(InboundEmail $email): void
    {
        $this->assertSame(InboundEmail::STATUS_POSTED, $email->status, (string) $email->reason);
        $message = SupportTicketMessage::query()->latest('id')->first();
        $this->assertNotNull($message);
        $this->assertTrue($message->via_email);
        $this->assertSame($message->getMorphClass(), $email->posted_type);
        $this->assertSame($message->id, (int) $email->posted_id);
    }

    private function assertQuarantined(InboundEmail $email, string $reason): void
    {
        $this->assertSame(InboundEmail::STATUS_QUARANTINED, $email->status);
        $this->assertSame($reason, $email->reason);
        $this->assertSame(0, SupportTicketMessage::query()->where('via_email', true)->count());
    }
}
