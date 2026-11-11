<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Local\ClientReplyBuilder;
use App\Core\Mail\Inbound\Local\OriginalEmail;
use App\Core\Mail\Inbound\Parsing\InboundEmailParser;
use App\Core\Mail\Inbound\Parsing\ParsedInboundEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The client reply builder and tickets:inbound-reply-as (#757).
 */
class ClientReplyBuilderTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableEmailReplies();
        $this->owner = User::factory()->create(['email' => 'owner@example.org', 'name' => 'Olive Owner']);
    }

    public function test_a_reply_threads_to_the_original_email(): void
    {
        $reply = $this->parsed();

        $this->assertSame('owner@example.org', $reply->fromAddress);
        $this->assertSame(['communications+t12.abc@example.test'], $reply->recipients);
        $this->assertSame('Re: Boiler is broken [MYAPES-T-12]', $reply->subject);
        $this->assertSame(['original-1@example.test'], $reply->headers->messageIds('in-reply-to'));
        $this->assertSame(['original-1@example.test'], $reply->headers->messageIds('references'));
        $this->assertStringContainsString('mx.example.test; spf=pass', (string) $reply->headers->first('authentication-results'));
        $this->assertStringStartsWith('Thanks, that fixed it.', $reply->textBody);
        $this->assertStringContainsString('> '.__('mail.reply_by_email.marker'), $reply->textBody);
        $this->assertStringContainsString('> Your ticket was updated.', $reply->textBody);
        $this->assertNotSame('original-1@example.test', $reply->messageId);
    }

    public function test_the_no_tag_variant_drops_only_the_subject_tag(): void
    {
        $reply = $this->parsed([ClientReplyBuilder::NO_TAG]);

        $this->assertSame('Re: Boiler is broken', $reply->subject);
        $this->assertSame(['original-1@example.test'], $reply->headers->messageIds('in-reply-to'));
    }

    public function test_the_no_references_variant_keeps_only_the_subject_tag(): void
    {
        $reply = $this->parsed([ClientReplyBuilder::NO_REFERENCES]);

        $this->assertSame('Re: Boiler is broken [MYAPES-T-12]', $reply->subject);
        $this->assertFalse($reply->headers->has('in-reply-to'));
        $this->assertFalse($reply->headers->has('references'));
    }

    public function test_the_edited_subject_variant_keeps_the_headers(): void
    {
        $reply = $this->parsed([ClientReplyBuilder::EDITED_SUBJECT]);

        $this->assertSame(ClientReplyBuilder::EDITED_SUBJECT_TEXT, $reply->subject);
        $this->assertSame(['original-1@example.test'], $reply->headers->messageIds('references'));
    }

    public function test_the_html_only_variant_has_no_text_part(): void
    {
        $reply = $this->parsed([ClientReplyBuilder::HTML_ONLY]);

        $this->assertSame('', trim($reply->textBody));
        $this->assertStringContainsString('<p>Thanks, that fixed it.</p>', $reply->htmlBody);
    }

    public function test_the_attachment_variant_adds_one_file(): void
    {
        $reply = $this->parsed([ClientReplyBuilder::ATTACHMENT]);

        $this->assertCount(1, $reply->attachments);
    }

    public function test_another_sender_replies_from_their_own_address(): void
    {
        $other = User::factory()->create(['email' => 'other@example.net']);

        $reply = $this->parsed([], $other);

        $this->assertSame('other@example.net', $reply->fromAddress);
        $this->assertStringContainsString('smtp.mailfrom=example.net', (string) $reply->headers->first('authentication-results'));
    }

    public function test_unknown_variants_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ClientReplyBuilder::class)->build($this->original(), $this->owner, 'Hi', ['bogus']);
    }

    public function test_the_command_refuses_to_run_outside_local(): void
    {
        $this->artisan('tickets:inbound-reply-as', ['--latest' => 'T12'])
            ->expectsOutputToContain('local testing only')
            ->assertFailed();
    }

    public function test_the_command_replies_to_the_latest_email_for_a_record_through_the_mail_settings(): void
    {
        $this->app['env'] = 'local';
        config(['inbound-mail.mailpit.url' => 'http://127.0.0.1:8025']);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/api/v1/search')) {
                return Http::response(['messages' => [
                    ['ID' => 'reply-id', 'Subject' => 'Re: Boiler is broken [MYAPES-T-12]'],
                    ['ID' => 'orig-id', 'Subject' => 'Boiler is broken [MYAPES-T-12]'],
                ], 'messages_count' => 2]);
            }

            return Http::response([
                'MessageID' => 'original-1@example.test',
                'Subject' => 'Boiler is broken [MYAPES-T-12]',
                'From' => ['Name' => 'MyAPES', 'Address' => 'no-reply@example.test'],
                'To' => [['Name' => 'Olive Owner', 'Address' => 'owner@example.org']],
                'ReplyTo' => [['Name' => '', 'Address' => 'communications+t12.abc@example.test']],
                'Text' => 'Your ticket was updated.',
            ]);
        });

        $this->artisan('tickets:inbound-reply-as', ['--latest' => 'T12', '--body' => 'Replying from Laragon.', '--variant' => ['no-tag']])
            ->expectsOutputToContain('from owner@example.org to communications+t12.abc@example.test')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v1/message/orig-id'));
        $sent = $this->sentEmails();
        $this->assertCount(1, $sent);
        $this->assertSame('Re: Boiler is broken', $sent[0]->getSubject());
        $this->assertSame('owner@example.org', $sent[0]->getFrom()[0]->getAddress());
    }

    private function original(): OriginalEmail
    {
        return new OriginalEmail(
            messageId: 'original-1@example.test',
            subject: 'Boiler is broken [MYAPES-T-12]',
            replyTo: 'communications+t12.abc@example.test',
            recipient: 'owner@example.org',
            text: 'Your ticket was updated.',
        );
    }

    /**
     * @param  list<string>  $variants
     */
    private function parsed(array $variants = [], ?User $sender = null): ParsedInboundEmail
    {
        $builder = app(ClientReplyBuilder::class);

        return app(InboundEmailParser::class)->parse($builder->raw($builder->build($this->original(), $sender ?? $this->owner, 'Thanks, that fixed it.', $variants)));
    }
}
