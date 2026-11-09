<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailStore;
use App\Core\Mail\Inbound\Parsing\InboundEmailParser;
use App\Core\Mail\Inbound\Parsing\ReplyTextExtractor;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Tickets\Models\SupportTicketMessage;
use Tests\TestCase;

/**
 * Reply text extraction (#581).
 */
class ReplyTextExtractionTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    /**
     * Each client quotes the original differently; the sender's own sign-off is kept.
     *
     * @return array<string, array{string, string}>
     */
    public static function clients(): array
    {
        return [
            'gmail' => ['gmail-reply', ''],
            'outlook' => ['outlook-reply', "\n\nKind regards\nAnn"],
            'apple mail' => ['apple-mail-reply', ''],
            'plain text' => ['plain-reply', ''],
        ];
    }

    #[DataProvider('clients')]
    public function test_only_the_new_text_is_kept(string $fixture, string $signOff): void
    {
        $text = $this->extract($fixture, 'Thanks, the boiler works again.');

        $this->assertSame('Thanks, the boiler works again.'.$signOff, $text);
        $this->assertStringNotContainsString('Reply above this line', $text);
        $this->assertStringNotContainsString('Sent from my', $text);
        $this->assertStringNotContainsString('Sam Staff', $text);
    }

    public function test_multi_paragraph_replies_keep_their_paragraphs(): void
    {
        $text = $this->extract('gmail-reply', "First paragraph.\n\nSecond paragraph.");

        $this->assertSame("First paragraph.\n\nSecond paragraph.", $text);
    }

    public function test_html_only_mail_becomes_plain_text(): void
    {
        $text = $this->extract('html-only-reply', 'Fixed now.');

        $this->assertStringContainsString('Fixed now.', $text);
        $this->assertStringContainsString('It is much warmer now & quiet.', $text);
        $this->assertStringNotContainsString('<', $text);
        $this->assertStringNotContainsString('alert', $text);
        $this->assertStringNotContainsString('Reply above this line', $text);
    }

    public function test_a_reply_with_no_new_text_is_empty(): void
    {
        $this->assertSame('', $this->extract('gmail-reply', ''));
        $this->assertSame('', $this->extract('plain-reply', "   \n  "));
    }

    public function test_posted_replies_store_escaped_text_not_html(): void
    {
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Storage::fake('private');
        Notification::fake();
        $this->enableEmailReplies();
        $owner = User::factory()->create(['email' => 'owner@example.org']);
        $ticket = $this->ticketFor($owner);

        $email = app(InboundMailStore::class)->store($this->fixture('plain-reply', [
            'TO' => $this->replyAddressFor('T', $ticket->id, $owner),
            'BODY' => "<script>alert('x')</script> Done & dusted",
        ]))?->fresh();

        $this->assertSame(InboundEmail::STATUS_POSTED, $email?->status);
        $message = (string) SupportTicketMessage::query()->sole()->message;
        $this->assertStringNotContainsString('<script', $message);
        $this->assertStringContainsString('Done &amp; dusted', $message);
    }

    public function test_an_empty_reply_is_rejected_and_not_posted(): void
    {
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Storage::fake('private');
        Notification::fake();
        $this->enableEmailReplies();
        $owner = User::factory()->create(['email' => 'owner@example.org']);
        $ticket = $this->ticketFor($owner);

        $email = app(InboundMailStore::class)->store($this->fixture('gmail-reply', [
            'TO' => $this->replyAddressFor('T', $ticket->id, $owner),
            'BODY' => '',
        ]))?->fresh();

        $this->assertSame(InboundEmail::STATUS_REJECTED, $email?->status);
        $this->assertSame('empty_reply', $email?->reason);
        $this->assertSame(0, SupportTicketMessage::query()->count());
    }

    private function extract(string $fixture, string $body): string
    {
        $parsed = app(InboundEmailParser::class)->parse($this->fixture($fixture, ['BODY' => $body]));

        return app(ReplyTextExtractor::class)->extract($parsed);
    }
}
