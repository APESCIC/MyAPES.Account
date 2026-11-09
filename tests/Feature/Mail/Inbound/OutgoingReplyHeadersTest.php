<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\MailReplyThread;
use App\Core\Mail\Inbound\ReplyToken;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Plugins\Cases\Notifications\ApesCicCaseUpdatedNotification;
use Plugins\Cases\Notifications\ShelterCaseUpdatedNotification;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Ticket and case emails carry reply-by-email headers (#578).
 */
class OutgoingReplyHeadersTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_ticket_email_has_token_reply_to_subject_tag_message_id_and_marker(): void
    {
        $this->enableEmailReplies();
        $owner = User::factory()->create();
        $ticket = $this->ticketFor($owner);

        $email = $this->sendTicketEmail($ticket, $owner);

        $this->assertCount(1, $email->getReplyTo());
        $this->assertSame($this->replyAddressFor('T', $ticket->id, $owner), $email->getReplyTo()[0]->getAddress());
        $this->assertStringEndsWith('[MYAPES-T-'.$ticket->id.']', (string) $email->getSubject());
        $this->assertSame('auto-generated', $email->getHeaders()->getHeaderBody('Auto-Submitted'));

        $messageId = (string) $email->getHeaders()->get('Message-ID')?->getBodyAsString();
        $thread = MailReplyThread::query()->sole();
        $this->assertSame('<'.$thread->message_id.'>', $messageId);
        $this->assertSame(['T', $ticket->id, $owner->id], [$thread->record_prefix, $thread->record_id, $thread->user_id]);

        $html = (string) $email->getHtmlBody();
        $this->assertStringContainsString(__('mail.reply_by_email.marker'), $html);
        $this->assertStringContainsString(__('mail.reply_by_email.line'), $html);
        $this->assertStringContainsString(__('mail.reply_by_email.footer'), $html);
        $this->assertStringNotContainsString(__('mail.brand.footer.no_reply'), $html);
        $this->assertStringContainsString(__('mail.reply_by_email.marker'), (string) $email->getTextBody());
    }

    public function test_case_emails_use_the_case_prefix(): void
    {
        $this->enableEmailReplies();
        $owner = User::factory()->create();
        $staff = User::factory()->create();
        $cic = $this->apesCicCaseFor($owner);
        $shelter = $this->shelterCaseFor($owner);

        Notification::sendNow($owner, new ApesCicCaseUpdatedNotification($cic, $staff, 'updated', 'apes-cic', 'apes-cic.cases.show'), ['mail']);
        Notification::sendNow($owner, new ShelterCaseUpdatedNotification($shelter, $staff, 'updated'), ['mail']);

        [$cicEmail, $shelterEmail] = $this->sentEmails();
        $this->assertSame($this->replyAddressFor('C', $cic->id, $owner), $cicEmail->getReplyTo()[0]->getAddress());
        $this->assertStringContainsString('[MYAPES-C-'.$cic->id.']', (string) $cicEmail->getSubject());
        $this->assertSame($this->replyAddressFor('C', $shelter->id, $owner), $shelterEmail->getReplyTo()[0]->getAddress());
        $this->assertSame(2, MailReplyThread::query()->where('record_prefix', 'C')->count());
    }

    public function test_each_recipient_gets_their_own_token(): void
    {
        $this->enableEmailReplies();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ticket = $this->ticketFor($owner);

        $ownerAddress = $this->sendTicketEmail($ticket, $owner)->getReplyTo()[0]->getAddress();
        $otherAddress = $this->sendTicketEmail($ticket, $other)->getReplyTo()[0]->getAddress();

        $this->assertNotSame($ownerAddress, $otherAddress);
    }

    public function test_without_replies_by_email_the_no_reply_address_stays(): void
    {
        config(['mail.default' => 'array', 'inbound-mail.enabled' => false, 'mail.reply_to.address' => 'no-reply@example.test']);
        $this->app->forgetInstance('mail.manager');
        $this->app->forgetInstance('mailer');
        $owner = User::factory()->create();
        $ticket = $this->ticketFor($owner);

        $email = $this->sendTicketEmail($ticket, $owner);

        $this->assertSame('no-reply@example.test', $email->getReplyTo()[0]->getAddress());
        $this->assertStringNotContainsString('[MYAPES-', (string) $email->getSubject());
        $this->assertSame('auto-generated', $email->getHeaders()->getHeaderBody('Auto-Submitted'));
        $this->assertStringContainsString(__('mail.brand.footer.no_reply'), (string) $email->getHtmlBody());
        $this->assertStringNotContainsString(__('mail.reply_by_email.marker'), (string) $email->getHtmlBody());
        $this->assertSame(0, MailReplyThread::query()->count());
    }

    public function test_replies_stay_off_without_a_token_key(): void
    {
        $this->enableEmailReplies(['inbound-mail.token_key' => '']);
        $owner = User::factory()->create();

        $email = $this->sendTicketEmail($this->ticketFor($owner), $owner);

        $this->assertStringNotContainsString('communications', $email->getReplyTo()[0]->getAddress());
    }

    public function test_without_plus_addressing_the_plain_mailbox_is_used(): void
    {
        $this->enableEmailReplies(['inbound-mail.plus_addressing' => false]);
        $owner = User::factory()->create();
        $ticket = $this->ticketFor($owner);

        $email = $this->sendTicketEmail($ticket, $owner);

        $this->assertSame('communications@example.test', $email->getReplyTo()[0]->getAddress());
        $this->assertStringContainsString('[MYAPES-T-'.$ticket->id.']', (string) $email->getSubject());
    }

    public function test_tokens_use_the_dedicated_key_never_app_key(): void
    {
        $this->enableEmailReplies();
        $tokens = app(ReplyToken::class);
        $signature = $tokens->signature('T', 12, 34);

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertSame($signature, $tokens->signature('T', 12, 34));

        config(['inbound-mail.token_key' => 'another-key']);
        $this->assertNotSame($signature, $tokens->signature('T', 12, 34));

        $this->assertTrue($tokens->verify('T', 12, 34, $tokens->signature('T', 12, 34)));
        $this->assertFalse($tokens->verify('T', 12, 35, $tokens->signature('T', 12, 34)));
        $this->assertFalse($tokens->verify('C', 12, 34, $tokens->signature('T', 12, 34)));
    }

    public function test_tokens_and_tags_are_read_back(): void
    {
        $this->enableEmailReplies();
        $tokens = app(ReplyToken::class);
        $address = 'Communications+'.strtoupper($tokens->localTag('T', 42, 7)).'@Example.test';

        $this->assertSame(['prefix' => 'T', 'id' => 42, 'signature' => $tokens->signature('T', 42, 7)], $tokens->fromAddress($address));
        $this->assertNull($tokens->fromAddress('someone+t42.abc@example.test'));
        $this->assertNull($tokens->fromAddress('communications@example.test'));
        $this->assertSame(['prefix' => 'C', 'id' => 9], ReplyToken::fromSubject('RE: APES CIC case #9 updated [MYAPES-C-9]'));
        $this->assertNull(ReplyToken::fromSubject('No tag here'));
    }

    private function sendTicketEmail(mixed $ticket, User $recipient): Email
    {
        $before = count($this->sentEmails());
        $actor = User::factory()->create();

        Notification::sendNow($recipient, new TicketUpdatedNotification($ticket, $actor, 'updated', 'apes-cic', 'apes-cic.tickets.show', 'APES CIC'), ['mail']);

        $emails = $this->sentEmails();
        $this->assertCount($before + 1, $emails);

        return end($emails);
    }
}
