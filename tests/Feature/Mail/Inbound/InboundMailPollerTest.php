<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\Inbound\InboundMailStore;
use App\Jobs\ProcessInboundEmail;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Inbound mail store and IMAP poller (#579).
 */
class InboundMailPollerTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private FakeMailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Queue::fake();
        $this->mailbox = new FakeMailbox;
        $this->app->instance(InboundMailbox::class, $this->mailbox);
    }

    public function test_poller_does_nothing_until_the_mailbox_is_configured(): void
    {
        $this->enableEmailReplies(['inbound-mail.imap.password' => null]);
        $this->mailbox->add($this->fixture('plain-reply'));

        $this->assertSame(InboundMailPoller::RESULT_DISABLED, app(InboundMailPoller::class)->poll()['result']);
        $this->artisan('tickets:inbound-poll')->expectsOutputToContain('not configured')->assertSuccessful();
        $this->assertCount(1, $this->mailbox->messages);
        $this->assertSame(0, InboundEmail::query()->count());
    }

    public function test_messages_are_stored_queued_and_removed_from_the_mailbox(): void
    {
        $this->configureMailbox();
        $this->mailbox->add($this->fixture('plain-reply', ['MESSAGE_ID' => 'one@mail.example.org', 'SUBJECT' => 'Re: one']));
        $this->mailbox->add($this->fixture('plain-reply', ['MESSAGE_ID' => 'two@mail.example.org', 'SUBJECT' => 'Re: two']));

        $result = app(InboundMailPoller::class)->poll();

        $this->assertSame(['result' => 'polled', 'fetched' => 2, 'stored' => 2, 'duplicates' => 0, 'failed' => 0], $result);
        $this->assertSame([], $this->mailbox->messages);
        $this->assertTrue($this->mailbox->expunged);

        $email = InboundEmail::query()->where('message_id', 'one@mail.example.org')->sole();
        $this->assertSame(InboundEmail::STATUS_RECEIVED, $email->status);
        $this->assertSame('owner@example.org', $email->from_address);
        $this->assertSame('example.org', $email->from_domain);
        $this->assertSame('Re: one', $email->subject);
        Storage::disk('private')->assertExists((string) $email->raw_path);
        Queue::assertPushed(ProcessInboundEmail::class, 2);
    }

    public function test_a_duplicate_message_id_is_stored_once(): void
    {
        $this->configureMailbox();
        $raw = $this->fixture('plain-reply', ['MESSAGE_ID' => 'same@mail.example.org']);
        $this->mailbox->add($raw);
        $this->mailbox->add($raw);

        $result = app(InboundMailPoller::class)->poll();

        $this->assertSame(1, $result['stored']);
        $this->assertSame(1, $result['duplicates']);
        $this->assertSame(1, InboundEmail::query()->count());
        $this->assertSame([], $this->mailbox->messages);
        $this->assertNull(app(InboundMailStore::class)->store($raw));
        Queue::assertPushed(ProcessInboundEmail::class, 1);
    }

    public function test_an_imap_outage_loses_nothing(): void
    {
        $this->configureMailbox();
        $this->mailbox->add($this->fixture('plain-reply'));
        $this->mailbox->unavailable = true;

        $this->assertSame(InboundMailPoller::RESULT_UNAVAILABLE, app(InboundMailPoller::class)->poll()['result']);
        $this->travel(61)->seconds();
        $this->artisan('tickets:inbound-poll')->expectsOutputToContain('could not be reached')->assertSuccessful();
        $this->assertCount(1, $this->mailbox->messages);

        $this->mailbox->unavailable = false;
        $this->travel(3)->minutes();
        app(InboundMailPoller::class)->poll();

        $this->assertSame(1, InboundEmail::query()->count());
        $this->assertSame([], $this->mailbox->messages);
    }

    public function test_a_message_that_cannot_be_stored_stays_in_the_mailbox(): void
    {
        $this->configureMailbox();
        $this->mailbox->add(null);
        $this->mailbox->add($this->fixture('plain-reply'));

        $result = app(InboundMailPoller::class)->poll();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['stored']);
        $this->assertSame([0 => null], $this->mailbox->messages);
        $this->artisan('tickets:inbound-poll')->assertFailed();
    }

    public function test_oversized_mail_is_recorded_without_keeping_the_body(): void
    {
        $this->configureMailbox(['inbound-mail.max_message_bytes' => 100]);

        $email = app(InboundMailStore::class)->store($this->fixture('plain-reply'));

        $this->assertSame(InboundEmail::STATUS_REJECTED, $email?->status);
        $this->assertSame('too_large', $email?->reason);
        $this->assertNull($email?->raw_path);
        Queue::assertNothingPushed();
    }

    public function test_the_poll_runs_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'tickets:inbound-poll'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function configureMailbox(array $overrides = []): void
    {
        $this->enableEmailReplies([
            'inbound-mail.imap.host' => 'imap.example.test',
            'inbound-mail.imap.username' => 'communications@example.test',
            'inbound-mail.imap.password' => 'not-a-real-password',
            ...$overrides,
        ]);
    }
}
