<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\Diagnostics\FailureType;
use App\Core\Mail\Inbound\Diagnostics\MailboxConnection;
use App\Core\Mail\Inbound\Diagnostics\MailboxDiagnostics;
use App\Core\Mail\Inbound\Diagnostics\SocketTransport;
use App\Core\Mail\Inbound\ImapMailbox;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\MailboxUnavailable;
use App\Core\Mail\Inbound\ReplyMailboxStatus;
use App\Services\AuthorizationProfile;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Poll lock, timeouts and backoff (#778).
 */
class InboundMailBackoffTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private FakeMailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Queue::fake();
        $this->enableEmailReplies([
            'inbound-mail.imap.host' => 'imap.example.test',
            'inbound-mail.imap.username' => 'communications@example.test',
            'inbound-mail.imap.password' => 'not-a-real-password',
        ]);
        $this->mailbox = new FakeMailbox;
        $this->app->instance(InboundMailbox::class, $this->mailbox);
    }

    public function test_each_failure_in_a_row_waits_longer_and_a_success_resets_it(): void
    {
        $this->mailbox->unavailable = true;
        $status = app(ReplyMailboxStatus::class);

        foreach ([1, 2, 5, 10, 15, 15] as $failure => $minutes) {
            $this->assertSame(InboundMailPoller::RESULT_UNAVAILABLE, $this->poll());
            $this->assertSame($failure + 1, $status->consecutiveFailures());
            $this->assertSame(now()->addMinutes($minutes)->toIso8601String(), $status->nextPollAt()?->toIso8601String(), "failure {$failure}");

            $this->travel($minutes)->minutes();
            $this->travel(1)->seconds();
        }

        $this->mailbox->unavailable = false;
        $this->assertSame(InboundMailPoller::RESULT_POLLED, $this->poll());
        $this->assertSame(0, $status->consecutiveFailures());
        $this->assertNull($status->nextPollAt());
    }

    public function test_a_scheduled_poll_does_not_connect_while_backing_off(): void
    {
        $this->mailbox->unavailable = true;
        $this->poll();
        $this->poll();
        $this->mailbox->unavailable = false;

        $this->assertSame(InboundMailPoller::RESULT_BACKING_OFF, $this->poll());
        $this->artisan('tickets:inbound-poll')->expectsOutputToContain('Waiting after failed polls')->assertSuccessful();
        $this->assertSame([], $this->mailbox->requestedAfter);

        $this->assertSame(InboundMailPoller::RESULT_POLLED, app(InboundMailPoller::class)->poll(force: true)['result']);
    }

    public function test_two_overlapping_polls_never_both_fetch(): void
    {
        $this->mailbox->add($this->fixture('plain-reply'));
        $lock = Cache::lock('inbound-mail:poll-lock', 300);
        $this->assertTrue($lock->get());

        $this->assertSame(InboundMailPoller::RESULT_LOCKED, $this->poll());
        $this->artisan('tickets:inbound-poll')->expectsOutputToContain('Another poll is running')->assertSuccessful();
        $this->assertSame([], $this->mailbox->requestedAfter);

        $lock->release();
        $this->assertSame(InboundMailPoller::RESULT_POLLED, $this->poll());
        $this->assertCount(1, $this->mailbox->requestedAfter);
    }

    public function test_polling_every_minute_continues_when_healthy(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'tickets:inbound-poll'));
        $this->assertSame('* * * * *', $event?->expression);
        $this->assertTrue($event->withoutOverlapping);

        foreach (range(1, 3) as $minute) {
            $this->assertSame(InboundMailPoller::RESULT_POLLED, $this->poll());
            $this->travel(1)->minutes();
        }
        $this->assertCount(3, $this->mailbox->requestedAfter);
        $this->assertSame(50, (int) config('inbound-mail.poll_batch'));
    }

    public function test_the_import_page_shows_when_the_next_poll_runs_while_backing_off(): void
    {
        $this->mailbox->unavailable = true;
        $this->poll();
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($admin)->get(route('admin.emails.import.index'))
            ->assertSee(__('admin.emails.import.backing_off', ['count' => 1, 'at' => now()->addMinute()->format('Y-m-d H:i')]));

        $this->travel(2)->minutes();
        $this->actingAs($admin)->get(route('admin.emails.import.index'))->assertDontSee('data-next-poll', false);
    }

    public function test_a_hanging_server_ends_the_poll_and_the_test_within_the_timeout(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($server);
        [, $port] = explode(':', (string) stream_socket_get_name($server, false));
        config([
            'inbound-mail.imap.host' => '127.0.0.1',
            'inbound-mail.imap.port' => (int) $port,
            'inbound-mail.imap.encryption' => 'ssl',
            'inbound-mail.imap.validate_cert' => false,
            'inbound-mail.imap.timeout' => 1,
        ]);

        try {
            $started = microtime(true);
            try {
                (new ImapMailbox(app(InboundMailSettings::class)))->test();
                $this->fail('A server that never answers must not pass.');
            } catch (MailboxUnavailable) {
                $this->assertLessThan(10, microtime(true) - $started);
            }

            $started = microtime(true);
            $report = (new MailboxDiagnostics(new SocketTransport))->run(new MailboxConnection('127.0.0.1', (int) $port, 'ssl', false, 'communications@example.test', 'not-a-real-password', timeout: 1));
            $this->assertLessThan(10, microtime(true) - $started);
            $this->assertSame(FailureType::Timeout, $report->failure);
        } finally {
            fclose($server);
        }
    }

    public function test_the_default_timeout_is_15_seconds(): void
    {
        $config = require base_path('config/inbound-mail.php');

        $this->assertSame(15, $config['imap']['timeout']);
    }

    private function poll(): string
    {
        return app(InboundMailPoller::class)->poll()['result'];
    }
}
