<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\Herd\HerdMailbox;
use App\Core\Mail\Inbound\Herd\HerdMailRunner;
use App\Core\Mail\Inbound\ImapMailbox;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\MailboxUnavailable;
use App\Jobs\ProcessInboundEmail;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Plugins\Tickets\Models\SupportTicketMessage;
use Tests\TestCase;

/**
 * The local Herd reply mailbox (#816).
 */
class HerdMailboxTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private FakeHerdMailRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Storage::fake('private');
        $this->runner = new FakeHerdMailRunner;
        $this->app->instance(HerdMailRunner::class, $this->runner);
    }

    public function test_herd_is_used_locally(): void
    {
        $this->useHerd();

        $this->assertTrue(app(InboundMailSettings::class)->pollingConfigured());
        $this->assertInstanceOf(HerdMailbox::class, app(InboundMailbox::class));
    }

    public function test_herd_is_refused_outside_local_and_testing(): void
    {
        $this->useHerd();
        $this->app['env'] = 'production';
        Log::spy();

        $this->assertFalse(app(InboundMailSettings::class)->pollingConfigured());
        $this->assertInstanceOf(ImapMailbox::class, app(InboundMailbox::class));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'INBOUND_MAIL_DRIVER=herd'));
    }

    public function test_the_deploy_preflight_refuses_the_herd_driver(): void
    {
        config(['inbound-mail.driver' => 'herd']);
        $this->artisan('myapes:inbound-mail:preflight')->expectsOutputToContain('INBOUND_MAIL_DRIVER is "herd"')->assertFailed();

        config(['inbound-mail.driver' => 'imap']);
        $this->artisan('myapes:inbound-mail:preflight')->assertSuccessful();
    }

    public function test_the_poller_imports_a_reply_from_herd_and_posts_it_to_the_ticket(): void
    {
        $this->useHerd();
        $owner = User::factory()->create(['email' => 'owner@example.org']);
        $ticket = $this->ticketFor($owner);
        $to = $this->replyAddressFor('T', $ticket->id, $owner);
        $raw = $this->fixture('plain-reply', [
            'FROM' => 'owner@example.org',
            'TO' => $to,
            'MESSAGE_ID' => 'herd-post@mail.example.org',
            'BODY' => 'Herd driver posted this reply.',
        ]);
        $this->runner->put('10', $to, '2026-11-14T10:00:00Z', $raw);

        $result = app(InboundMailPoller::class)->poll();
        ProcessInboundEmail::dispatchSync((int) InboundEmail::query()->sole()->id);

        $this->assertSame(InboundMailPoller::RESULT_POLLED, $result['result']);
        $this->assertSame(1, $result['stored']);
        $this->assertSame(InboundEmail::STATUS_POSTED, InboundEmail::query()->sole()->status);
        $this->assertStringContainsString('Herd driver posted this reply.', (string) SupportTicketMessage::query()->sole()->message);
        $this->assertContains(['mail:delete', '10'], $this->runner->calls);
    }

    public function test_a_mail_is_never_imported_twice_across_polls(): void
    {
        $this->useHerd();
        $raw = $this->fixture('plain-reply', [
            'TO' => 'communications@example.test',
            'MESSAGE_ID' => 'herd-once@mail.example.org',
        ]);
        $this->runner->put('7', 'communications@example.test', '2026-11-14T10:00:00Z', $raw);
        Queue::fake();

        $first = app(InboundMailPoller::class)->poll();
        $this->assertSame(1, $first['stored']);
        $this->assertContains(['mail:delete', '7'], $this->runner->calls);
        $this->assertContains('7', Cache::get('inbound-mail:herd-handled-ids', []));

        // Herd still lists the id (delete failed or race); handled-id cache must skip it.
        $this->runner->deleted = [];
        $second = app(InboundMailPoller::class)->poll();

        $this->assertSame(['result' => 'polled', 'fetched' => 0, 'stored' => 0, 'duplicates' => 0, 'failed' => 0], $second);
        $this->assertSame(1, InboundEmail::query()->count());
    }

    private function useHerd(): void
    {
        $this->enableEmailReplies([
            'inbound-mail.driver' => 'herd',
        ]);
    }
}

/**
 * @internal
 */
final class FakeHerdMailRunner implements HerdMailRunner
{
    /** @var array<string, array{to: string, date: string, raw: string}> */
    public array $mail = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var list<list<string>> */
    public array $calls = [];

    public function put(string $id, string $to, string $date, string $raw): void
    {
        $this->mail[$id] = ['to' => $to, 'date' => $date, 'raw' => $raw];
    }

    public function run(array $arguments): string
    {
        $this->calls[] = $arguments;
        $command = $arguments[0] ?? '';

        return match ($command) {
            'mail:list' => $this->list($arguments),
            'mail:show' => $this->show($arguments),
            'mail:delete' => $this->delete($arguments),
            default => throw new MailboxUnavailable('Unexpected herd command: '.$command),
        };
    }

    /**
     * @param  list<string>  $arguments
     */
    private function list(array $arguments): string
    {
        $toFilter = null;
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--to=')) {
                $toFilter = substr($argument, 5);
            }
        }

        $rows = [];
        foreach ($this->mail as $id => $message) {
            if (in_array($id, $this->deleted, true)) {
                continue;
            }
            if ($toFilter !== null && ! str_contains(strtolower($message['to']), strtolower($toFilter))) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'date' => $message['date'],
                'mailbox' => 'Default',
                'subject' => 'Re: Ticket',
                'from' => [['address' => 'owner@example.org', 'name' => '']],
                'to' => [['address' => $message['to'], 'name' => '']],
                'cc' => [],
                'attachments' => 0,
            ];
        }

        return json_encode($rows, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function show(array $arguments): string
    {
        $id = $arguments[1] ?? '';
        if (! isset($this->mail[$id]) || ! in_array('--format=raw', $arguments, true)) {
            throw new MailboxUnavailable('Herd mail:show missing raw mail '.$id);
        }

        return $this->mail[$id]['raw'];
    }

    /**
     * @param  list<string>  $arguments
     */
    private function delete(array $arguments): string
    {
        $id = $arguments[1] ?? '';
        $this->deleted[] = $id;

        return "Deleted mail {$id}.\n";
    }
}
