<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailStore;
use App\Core\Mail\Inbound\Local\ClientReplyBuilder;
use App\Core\Mail\Inbound\Local\OriginalEmail;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Models\SupportTicketMessage;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * From an outgoing ticket email to a posted reply, with plus-addressing on
 * and off (#750). Replies are built with the shared client reply builder.
 */
class ReplyRoutingEndToEndTest extends TestCase
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
        $this->owner = User::factory()->create(['email' => 'owner@example.org', 'name' => 'Olive Owner']);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function plusAddressing(): array
    {
        return ['plus-addressing on' => [true], 'plus-addressing off' => [false]];
    }

    public function test_a_token_only_reply_is_posted_when_plus_addressing_is_on(): void
    {
        $original = $this->sendTicketEmail(true);
        $this->assertStringContainsString('+t'.$this->ticket->id.'.', $original->replyTo);

        $this->assertPosted($this->reply($original, [ClientReplyBuilder::NO_TAG, ClientReplyBuilder::NO_REFERENCES]));
    }

    #[DataProvider('plusAddressing')]
    public function test_a_header_only_reply_is_posted(bool $plus): void
    {
        $this->assertPosted($this->reply($this->sendTicketEmail($plus), [ClientReplyBuilder::NO_TAG]));
    }

    #[DataProvider('plusAddressing')]
    public function test_a_subject_only_reply_is_posted(bool $plus): void
    {
        $this->assertPosted($this->reply($this->sendTicketEmail($plus), [ClientReplyBuilder::NO_REFERENCES]));
    }

    #[DataProvider('plusAddressing')]
    public function test_an_edited_subject_with_headers_intact_is_posted(bool $plus): void
    {
        $this->assertPosted($this->reply($this->sendTicketEmail($plus), [ClientReplyBuilder::EDITED_SUBJECT]));
    }

    #[DataProvider('plusAddressing')]
    public function test_conflicting_references_are_held(bool $plus): void
    {
        $original = $this->sendTicketEmail($plus);
        $other = $this->ticketFor($this->owner, ['subject' => 'Another ticket']);
        $conflicting = new OriginalEmail($original->messageId, 'Another ticket [MYAPES-T-'.$other->id.']', $original->replyTo, $original->recipient, $original->text);

        $this->assertHeld($this->reply($conflicting), 'conflicting_reference');
    }

    #[DataProvider('plusAddressing')]
    public function test_another_account_replying_to_the_owners_email_is_held(bool $plus): void
    {
        $other = User::factory()->create(['email' => 'other@example.org']);

        $this->assertHeld($this->reply($this->sendTicketEmail($plus), [], $other), 'wrong_sender');
    }

    #[DataProvider('plusAddressing')]
    public function test_an_unknown_sender_is_held(bool $plus): void
    {
        $stranger = User::factory()->make(['email' => 'stranger@example.org']);

        $this->assertHeld($this->reply($this->sendTicketEmail($plus), [], $stranger), 'unknown_sender');
    }

    #[DataProvider('plusAddressing')]
    public function test_an_unmatched_reply_gets_one_notice_and_staff_can_attach_it(bool $plus): void
    {
        $original = $this->sendTicketEmail($plus);
        $unmatched = new OriginalEmail('', 'Hello', 'communications@example.test', $original->recipient, $original->text);

        $email = $this->reply($unmatched, [ClientReplyBuilder::NO_REFERENCES]);

        $this->assertHeld($email, 'unmatched');
        $this->assertTrue($email->notice_sent);
        $notice = collect($this->sentEmails())->last(fn (Email $sent): bool => $sent->getTo()[0]->getAddress() === 'owner@example.org');
        $this->assertSame('auto-replied', $notice?->getHeaders()->getHeaderBody('Auto-Submitted'));

        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $this->actingAs($admin)
            ->post(route('admin.emails.inbound.attach', $email), ['record' => 'T'.$this->ticket->id])
            ->assertSessionHasNoErrors();
        $this->assertPosted($email->fresh());
    }

    public function test_the_dedicated_token_key_signs_reply_addresses(): void
    {
        $original = $this->sendTicketEmail(true);
        $this->assertNotSame(config('app.key'), config('inbound-mail.token_key'));
        config(['inbound-mail.token_key' => 'a-different-key']);

        $this->assertHeld($this->reply($original, [ClientReplyBuilder::NO_TAG, ClientReplyBuilder::NO_REFERENCES]), 'wrong_sender');
    }

    private function sendTicketEmail(bool $plus): OriginalEmail
    {
        $this->enableEmailReplies(['inbound-mail.plus_addressing' => $plus]);
        $this->ticket ??= $this->ticketFor($this->owner);
        $before = count($this->sentEmails());

        Notification::sendNow($this->owner, new TicketUpdatedNotification($this->ticket, User::factory()->create(), 'updated', 'apes-cic', 'apes-cic.tickets.show', 'APES CIC'), ['mail']);

        $emails = $this->sentEmails();
        $this->assertCount($before + 1, $emails);
        $sent = end($emails);

        return new OriginalEmail(
            messageId: trim((string) $sent->getHeaders()->get('Message-ID')?->getBodyAsString(), '<> '),
            subject: (string) $sent->getSubject(),
            replyTo: $sent->getReplyTo()[0]->getAddress(),
            recipient: $sent->getTo()[0]->getAddress(),
            text: (string) $sent->getTextBody(),
        );
    }

    /**
     * @param  list<string>  $variants
     */
    private function reply(OriginalEmail $original, array $variants = [], ?User $sender = null): InboundEmail
    {
        $builder = app(ClientReplyBuilder::class);
        $raw = $builder->raw($builder->build($original, $sender ?? $this->owner, 'Thanks, that is sorted now.', $variants));
        $email = app(InboundMailStore::class)->store($raw);
        $this->assertNotNull($email);

        return $email->fresh();
    }

    private function assertPosted(InboundEmail $email): void
    {
        $this->assertSame(InboundEmail::STATUS_POSTED, $email->status, (string) $email->reason);
        $this->assertSame(['T', $this->ticket->id], [$email->record_prefix, (int) $email->record_id]);
        $message = SupportTicketMessage::query()->findOrFail($email->posted_id);
        $this->assertSame($this->owner->id, $message->user_id);
        $this->assertTrue($message->via_email);
        $this->assertStringContainsString('Thanks, that is sorted now.', (string) $message->message);
        $this->assertStringNotContainsString(__('mail.reply_by_email.marker'), (string) $message->message);
    }

    private function assertHeld(InboundEmail $email, string $reason): void
    {
        $this->assertSame([InboundEmail::STATUS_QUARANTINED, $reason], [$email->status, $email->reason]);
        $this->assertNull($email->posted_id);
        $this->assertSame(0, SupportTicketMessage::query()->where('via_email', true)->count());
    }
}
