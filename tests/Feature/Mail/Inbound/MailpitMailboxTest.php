<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\ImapMailbox;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\Mailpit\MailpitMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The local Mailpit reply mailbox (#756).
 */
class MailpitMailboxTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private const URL = 'http://127.0.0.1:8025';

    /** @var array<string, string> */
    private array $raw = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Queue::fake();
    }

    public function test_the_driver_defaults_to_imap(): void
    {
        $this->enableEmailReplies();

        $this->assertSame('imap', app(InboundMailSettings::class)->driver());
        $this->assertInstanceOf(ImapMailbox::class, app(InboundMailbox::class));
        $this->assertSame(self::URL, config('inbound-mail.mailpit.url'));
    }

    public function test_mailpit_is_used_locally_with_no_imap_details(): void
    {
        $this->useMailpit();

        $this->assertTrue(app(InboundMailSettings::class)->pollingConfigured());
        $this->assertInstanceOf(MailpitMailbox::class, app(InboundMailbox::class));
    }

    public function test_mailpit_is_refused_outside_local_and_testing(): void
    {
        $this->useMailpit();
        $this->app['env'] = 'production';
        Log::spy();

        $this->assertFalse(app(InboundMailSettings::class)->pollingConfigured());
        $this->assertInstanceOf(ImapMailbox::class, app(InboundMailbox::class));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'INBOUND_MAIL_DRIVER=mailpit'));
    }

    public function test_mailpit_is_refused_on_a_host_that_is_not_loopback(): void
    {
        $this->useMailpit(['inbound-mail.mailpit.url' => 'http://mailpit.example.org:8025']);

        $this->assertFalse(app(InboundMailSettings::class)->pollingConfigured());
        $this->assertInstanceOf(ImapMailbox::class, app(InboundMailbox::class));
    }

    public function test_the_poller_stores_mail_to_the_inbound_address_oldest_first_then_deletes_it(): void
    {
        $this->useMailpit();
        $this->fakeMailpit([
            $this->summary('new', 'communications+t-abc@example.test', '2026-11-11T10:05:00Z'),
            $this->summary('other', 'someone@example.org', '2026-11-11T10:01:00Z'),
            $this->summary('old', 'communications@example.test', '2026-11-11T10:00:00Z'),
            $this->summary('lookalike', 'communications@example.test.evil.org', '2026-11-11T09:00:00Z'),
        ]);

        $result = app(InboundMailPoller::class)->poll();

        $this->assertSame(['result' => 'polled', 'fetched' => 2, 'stored' => 2, 'duplicates' => 0, 'failed' => 0], $result);
        $this->assertSame(['old@mail.example.org', 'new@mail.example.org'], InboundEmail::query()->orderBy('id')->pluck('message_id')->all());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === self::URL.'/api/v1/messages'
            && $request->data() === ['IDs' => ['old', 'new']]);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/search') && $request['query'] === 'to:communications');
    }

    public function test_the_poll_batch_limits_how_many_are_fetched(): void
    {
        $this->useMailpit(['inbound-mail.poll_batch' => 1]);
        $this->fakeMailpit([
            $this->summary('second', 'communications@example.test', '2026-11-11T10:05:00Z'),
            $this->summary('first', 'communications@example.test', '2026-11-11T10:00:00Z'),
        ]);

        $this->assertSame(1, app(InboundMailPoller::class)->poll()['stored']);
        $this->assertSame('first@mail.example.org', InboundEmail::query()->sole()->message_id);
    }

    public function test_a_duplicate_is_stored_once_and_still_deleted(): void
    {
        $this->useMailpit();
        $this->fakeMailpit([$this->summary('one', 'communications@example.test', '2026-11-11T10:00:00Z')]);

        app(InboundMailPoller::class)->poll();
        $result = app(InboundMailPoller::class)->poll();

        $this->assertSame(1, $result['duplicates']);
        $this->assertSame(1, InboundEmail::query()->count());
        Http::assertSentCount(6);
    }

    public function test_mailpit_stopped_means_unavailable_and_nothing_is_lost(): void
    {
        $this->useMailpit();
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->assertSame(InboundMailPoller::RESULT_UNAVAILABLE, app(InboundMailPoller::class)->poll()['result']);
        $this->assertSame(0, InboundEmail::query()->count());
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_nothing_is_deleted_when_nothing_was_stored(): void
    {
        $this->useMailpit();
        $this->fakeMailpit([]);

        $this->assertSame(0, app(InboundMailPoller::class)->poll()['fetched']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_the_deploy_preflight_refuses_the_mailpit_driver(): void
    {
        config(['inbound-mail.driver' => 'mailpit']);
        $this->artisan('myapes:inbound-mail:preflight')->expectsOutputToContain('INBOUND_MAIL_DRIVER is "mailpit"')->assertFailed();

        config(['inbound-mail.driver' => 'imap']);
        $this->artisan('myapes:inbound-mail:preflight')->assertSuccessful();
    }

    private function useMailpit(array $overrides = []): void
    {
        $this->enableEmailReplies([
            'inbound-mail.driver' => 'mailpit',
            'inbound-mail.mailpit.url' => self::URL,
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(string $id, string $to, string $created): array
    {
        $this->raw[$id] = $this->fixture('plain-reply', ['MESSAGE_ID' => $id.'@mail.example.org', 'TO' => $to]);

        return ['ID' => $id, 'To' => [['Name' => '', 'Address' => $to]], 'Cc' => [], 'Created' => $created];
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private function fakeMailpit(array $messages): void
    {
        Http::fake(function (Request $request) use ($messages) {
            if (str_contains($request->url(), '/api/v1/search')) {
                return Http::response(['messages' => $messages, 'messages_count' => count($messages)]);
            }
            if (preg_match('#/api/v1/message/([^/]+)/raw$#', $request->url(), $m) === 1) {
                return Http::response($this->raw[$m[1]] ?? '', isset($this->raw[$m[1]]) ? 200 : 404);
            }
            if ($request->method() === 'DELETE') {
                return Http::response('ok');
            }

            return Http::response([], 404);
        });
    }
}
