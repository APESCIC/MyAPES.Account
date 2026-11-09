<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailStore;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Models\SupportTicketMessage;
use Tests\TestCase;

/**
 * Loop, auto-reply, bounce and rate-limit protection (#583).
 */
class LoopAndRateLimitTest extends TestCase
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

    public function test_an_auto_responder_never_posts_or_replies(): void
    {
        $email = $this->receive('auto-reply');

        $this->assertIgnored($email, 'auto_reply');
        Notification::assertNothingSent();
        $this->assertSame([], $this->sentEmails());
    }

    /**
     * @return array<string, array{string, array<string, string>, string}>
     */
    public static function machineMail(): array
    {
        return [
            'bounce' => ['bounce', [], 'bounce'],
            'precedence bulk' => ['plain-reply', ['EXTRA' => "\nPrecedence: bulk"], 'bulk'],
            'mailing list' => ['plain-reply', ['EXTRA' => "\nList-Id: <updates.example.org>"], 'mailing_list'],
            'x-autoreply' => ['plain-reply', ['EXTRA' => "\nX-Autoreply: yes"], 'auto_reply'],
            'out of office subject' => ['plain-reply', ['SUBJECT' => 'Out of Office: back Monday'], 'auto_reply'],
            'our own address' => ['plain-reply', ['FROM' => 'no-reply@apes.org.uk'], 'loop'],
            'our own mailbox' => ['plain-reply', ['FROM' => 'communications@example.test'], 'loop'],
        ];
    }

    /**
     * @param  array<string, string>  $values
     */
    #[DataProvider('machineMail')]
    public function test_machine_mail_is_ignored(string $fixture, array $values, string $reason): void
    {
        config(['mail.reply_to.address' => 'no-reply@apes.org.uk']);

        $this->assertIgnored($this->receive($fixture, $values), $reason);
    }

    public function test_auto_submitted_no_is_a_person(): void
    {
        $email = $this->receive('plain-reply', ['EXTRA' => "\nAuto-Submitted: no"]);

        $this->assertSame(InboundEmail::STATUS_POSTED, $email->status);
    }

    public function test_replies_over_the_sender_limit_are_logged_as_ignored(): void
    {
        config(['inbound-mail.rate_limits.per_sender_per_hour' => 2]);
        Log::spy();

        $this->receive('plain-reply', ['BODY' => 'One']);
        $this->receive('plain-reply', ['BODY' => 'Two']);
        $third = $this->receive('plain-reply', ['BODY' => 'Three']);

        $this->assertIgnored($third, 'rate_limited');
        $this->assertSame(2, SupportTicketMessage::query()->where('via_email', true)->count());
        Log::shouldHaveReceived('notice')->withArgs(fn (string $message): bool => str_contains($message, 'rate limit'))->once();

        $this->travel(61)->minutes();
        $this->assertSame(InboundEmail::STATUS_POSTED, $this->receive('plain-reply', ['BODY' => 'Four'])->status);
    }

    public function test_replies_over_the_record_limit_are_ignored(): void
    {
        config(['inbound-mail.rate_limits.per_record_per_hour' => 1]);
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create(['email' => 'staff@example.org']);

        $this->receive('plain-reply', ['BODY' => 'Owner reply']);
        $staffReply = $this->receive('plain-reply', [
            'FROM' => 'staff@example.org',
            'TO' => $this->replyAddressFor('T', $this->ticket->id, $staff),
            'BODY' => 'Staff reply',
        ]);

        $this->assertIgnored($staffReply, 'rate_limited');
    }

    /**
     * @param  array<string, string>  $values
     */
    private function receive(string $fixture, array $values = []): InboundEmail
    {
        $email = app(InboundMailStore::class)->store($this->fixture($fixture, [
            'TO' => $this->replyAddressFor('T', $this->ticket->id, $this->owner),
            ...$values,
        ]));
        $this->assertNotNull($email);

        return $email->fresh();
    }

    private function assertIgnored(InboundEmail $email, string $reason): void
    {
        $this->assertSame(InboundEmail::STATUS_IGNORED, $email->status, (string) $email->reason);
        $this->assertSame($reason, $email->reason);
        $this->assertFalse($email->notice_sent);
    }
}
