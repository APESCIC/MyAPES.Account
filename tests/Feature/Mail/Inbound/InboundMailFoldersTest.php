<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailPoller;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Processed and Failed folders in the mailbox (#776).
 */
class InboundMailFoldersTest extends TestCase
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

    /**
     * @return array<string, array{bool}>
     */
    public static function moveSupport(): array
    {
        return ['with MOVE' => [true], 'without MOVE (COPY then delete)' => [false]];
    }

    #[DataProvider('moveSupport')]
    public function test_stored_mail_lands_in_processed_and_is_never_fetched_again(bool $supportsMove): void
    {
        $this->mailbox->supportsMove = $supportsMove;
        $this->mailbox->add($this->reply(1));
        $this->mailbox->add($this->reply(1));

        $first = app(InboundMailPoller::class)->poll();
        $second = app(InboundMailPoller::class)->poll();

        $this->assertSame(['stored' => 1, 'duplicates' => 1], ['stored' => $first['stored'], 'duplicates' => $first['duplicates']]);
        $this->assertCount(2, $this->mailbox->folders[InboundMailPoller::FOLDER_PROCESSED]);
        $this->assertSame([], $this->mailbox->messages);
        $this->assertSame(0, $second['fetched']);
        $this->assertSame(1, InboundEmail::query()->count());
    }

    public function test_folders_are_created_when_missing(): void
    {
        app(InboundMailPoller::class)->poll();
        $this->assertSame([InboundMailPoller::FOLDER_PROCESSED, InboundMailPoller::FOLDER_FAILED], $this->mailbox->createdFolders);

        app(InboundMailPoller::class)->poll();
        $this->assertCount(2, $this->mailbox->createdFolders);
    }

    public function test_the_third_storage_failure_moves_the_message_to_failed(): void
    {
        $this->mailbox->add(null);

        app(InboundMailPoller::class)->poll();
        app(InboundMailPoller::class)->poll();
        $this->assertSame([0 => null], $this->mailbox->messages);
        $this->assertSame([], $this->mailbox->folders[InboundMailPoller::FOLDER_FAILED]);

        $third = app(InboundMailPoller::class)->poll();

        $this->assertSame(1, $third['failed']);
        $this->assertSame([], $this->mailbox->messages);
        $this->assertCount(1, $this->mailbox->folders[InboundMailPoller::FOLDER_FAILED]);
        $this->assertSame(0, app(InboundMailPoller::class)->poll()['fetched']);
    }

    public function test_the_daily_job_removes_only_messages_older_than_30_days(): void
    {
        $this->mailbox->ensureFolders([InboundMailPoller::FOLDER_PROCESSED, InboundMailPoller::FOLDER_FAILED]);
        $this->mailbox->folders[InboundMailPoller::FOLDER_PROCESSED] = [
            ['raw' => 'old', 'at' => now()->subDays(31)],
            ['raw' => 'recent', 'at' => now()->subDays(29)],
        ];
        $this->mailbox->folders[InboundMailPoller::FOLDER_FAILED] = [['raw' => 'old failure', 'at' => now()->subDays(40)]];

        $this->artisan('inbound-mail:empty-folders')
            ->expectsOutputToContain('Deleted 1 message(s) older than 30 days from Processed.')
            ->expectsOutputToContain('Deleted 1 message(s) older than 30 days from Failed.')
            ->assertSuccessful();

        $this->assertSame(['recent'], array_column($this->mailbox->folders[InboundMailPoller::FOLDER_PROCESSED], 'raw'));
        $this->assertSame([], $this->mailbox->folders[InboundMailPoller::FOLDER_FAILED]);
    }

    public function test_the_folder_job_runs_daily_and_does_nothing_until_polling_is_set_up(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'inbound-mail:empty-folders'));
        $this->assertSame('0 0 * * *', $event?->expression);

        config(['inbound-mail.imap.password' => null]);
        $this->artisan('inbound-mail:empty-folders')->expectsOutputToContain('nothing to empty')->assertSuccessful();
    }

    private function reply(int $n): string
    {
        return $this->fixture('plain-reply', ['MESSAGE_ID' => "folder-{$n}@mail.example.org"]);
    }
}
