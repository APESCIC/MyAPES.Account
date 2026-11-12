<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailboxState;
use App\Core\Mail\Inbound\InboundMailPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * UID tracking with UIDVALIDITY and the Message-ID check (#777).
 */
class InboundMailUidTrackingTest extends TestCase
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
            'inbound-mail.imap.username' => 'Communications@example.test',
            'inbound-mail.imap.password' => 'not-a-real-password',
        ]);
        $this->mailbox = new FakeMailbox;
        $this->app->instance(InboundMailbox::class, $this->mailbox);
    }

    public function test_a_poll_fetches_only_uids_above_the_stored_one(): void
    {
        foreach (range(1, 7) as $n) {
            $this->mailbox->add($this->reply($n));
        }
        InboundMailboxState::query()->create(['mailbox' => InboundMailboxState::mailboxKey('communications@example.test'), 'folder' => 'INBOX', 'uid_validity' => 1, 'last_uid' => 5]);

        $result = app(InboundMailPoller::class)->poll();

        $this->assertSame([5], $this->mailbox->requestedAfter);
        $this->assertSame([6, 7], $this->mailbox->fetchedUids);
        $this->assertSame(2, $result['stored']);
        $this->assertSame(7, InboundMailboxState::query()->sole()->last_uid);
    }

    public function test_the_first_poll_reads_the_whole_folder_and_records_where_it_got_to(): void
    {
        $this->mailbox->add($this->reply(1));
        $this->mailbox->add($this->reply(2));

        app(InboundMailPoller::class)->poll();

        $this->assertSame([null], $this->mailbox->requestedAfter);
        $state = InboundMailboxState::query()->sole();
        $this->assertSame(InboundMailboxState::mailboxKey('communications@example.test'), $state->mailbox);
        $this->assertSame('INBOX', $state->folder);
        $this->assertSame(1, $state->uid_validity);
        $this->assertSame(2, $state->last_uid);
    }

    public function test_a_changed_uidvalidity_rescans_and_imports_nothing_twice(): void
    {
        $this->mailbox->add($this->reply(1));
        $this->mailbox->add($this->reply(2));
        app(InboundMailPoller::class)->poll();

        // The server renumbers the folder: the same mail comes back under new UIDs.
        $this->mailbox->uidValidity = 2;
        $this->mailbox->add($this->reply(1));
        $this->mailbox->add($this->reply(2));
        $this->mailbox->add($this->reply(3));

        $result = app(InboundMailPoller::class)->poll();

        $this->assertNull(end($this->mailbox->requestedAfter));
        $this->assertSame(['stored' => 1, 'duplicates' => 2], ['stored' => $result['stored'], 'duplicates' => $result['duplicates']]);
        $this->assertSame(3, InboundEmail::query()->count());
        $this->assertSame(2, InboundMailboxState::query()->sole()->uid_validity);
    }

    public function test_mail_that_failed_to_store_is_fetched_again(): void
    {
        $this->mailbox->add($this->reply(1));
        $this->mailbox->add(null);
        $this->mailbox->add($this->reply(3));

        app(InboundMailPoller::class)->poll();

        $this->assertSame(1, InboundMailboxState::query()->sole()->last_uid);

        app(InboundMailPoller::class)->poll();
        $this->assertSame(1, end($this->mailbox->requestedAfter));
    }

    public function test_uid_state_survives_a_restart(): void
    {
        $this->mailbox->add($this->reply(1));
        app(InboundMailPoller::class)->poll();

        Cache::flush();
        $this->app->forgetInstance(InboundMailPoller::class);
        $this->mailbox->add($this->reply(2));
        app(InboundMailPoller::class)->poll();

        $this->assertSame([null, 1], $this->mailbox->requestedAfter);
        $this->assertDatabaseHas('inbound_mailbox_states', ['mailbox' => InboundMailboxState::mailboxKey('communications@example.test'), 'last_uid' => 2]);
    }

    private function reply(int $n): string
    {
        return $this->fixture('plain-reply', ['MESSAGE_ID' => "reply-{$n}@mail.example.org", 'SUBJECT' => "Re: {$n}"]);
    }
}
