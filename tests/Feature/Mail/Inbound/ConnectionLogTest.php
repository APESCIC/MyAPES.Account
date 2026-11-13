<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\Diagnostics\MailboxTransport;
use App\Core\Mail\Inbound\InboundMailboxLogEntry;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\Inbound\ReplyMailboxStatus;
use App\Services\AuthorizationProfile;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use ReflectionProperty;
use Tests\Fakes\FakeImapServer;
use Tests\TestCase;

/**
 * The recent connection log, and no secret kept anywhere (#780).
 */
class ConnectionLogTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private const PASSWORD = 'k3pt-n0where-mail-app-password';

    private const TYPED_PASSWORD = 'typed-once-0nly-pa55word';

    private const USERNAME = 'redaction.probe@example.test';

    private const SUBJECT = 'Re: private subject never logged';

    private FakeMailbox $mailbox;

    private FakeImapServer $server;

    private User $admin;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Queue::fake();
        Notification::fake();
        config([
            'app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null),
            'cache.default' => 'array',
        ]);
        $this->enableEmailReplies([
            'inbound-mail.imap.host' => 'imap.example.test',
            'inbound-mail.imap.username' => null,
            'inbound-mail.imap.password' => null,
        ]);
        $this->mailbox = new FakeMailbox;
        $this->server = new FakeImapServer;
        $this->app->instance(InboundMailbox::class, $this->mailbox);
        $this->app->instance(MailboxTransport::class, $this->server);
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    public function test_a_known_password_and_username_are_found_nowhere(): void
    {
        $this->actingAs($this->admin)->put(route('admin.emails.import.update'), [
            'enabled' => '1', 'address' => 'communications@example.test', 'host' => 'imap.example.test', 'port' => '993',
            'encryption' => 'ssl', 'validate_cert' => '1', 'username' => self::USERNAME, 'password' => self::PASSWORD,
            'folder' => 'INBOX', 'plus_addressing' => '1',
        ])->assertSessionHasNoErrors();

        $this->server->loginResponse = 'NO [AUTHENTICATIONFAILED] Authentication failed for '.self::USERNAME;
        $this->actingAs($this->admin)->put(route('admin.emails.import.test'), ['host' => 'imap.example.test', 'port' => '993', 'encryption' => 'ssl', 'username' => self::USERNAME, 'password' => self::TYPED_PASSWORD, 'folder' => 'INBOX']);
        $this->actingAs($this->admin)->put(route('admin.emails.import.test'), ['host' => 'imap.example.test', 'port' => '993', 'encryption' => 'ssl', 'username' => self::USERNAME, 'password' => '', 'folder' => 'INBOX']);

        $this->mailbox->unavailable = true;
        foreach (range(1, 3) as $attempt) {
            $next = app(ReplyMailboxStatus::class)->nextPollAt();
            if ($next?->isFuture()) {
                $this->travelTo($next->addSecond());
            }
            app(InboundMailPoller::class)->poll();
        }
        $this->mailbox->unavailable = false;
        $this->mailbox->add($this->fixture('plain-reply', ['SUBJECT' => self::SUBJECT]));
        $this->travel(20)->minutes();
        app(InboundMailPoller::class)->poll();
        $this->actingAs($this->admin)->get(route('admin.emails.import.index'))->assertOk()->assertDontSee(self::PASSWORD)->assertDontSee(self::TYPED_PASSWORD);

        $places = [
            'log' => implode("\n", $this->logged),
            'cache' => $this->cacheContents(),
            'audit' => json_encode(AuditLog::query()->get()->toArray()),
            'connection log' => json_encode(DB::table('inbound_mailbox_log')->get()),
            'uid state' => json_encode(DB::table('inbound_mailbox_states')->get()),
            'settings' => json_encode(DB::table('app_settings')->get()),
        ];

        foreach ($places as $where => $contents) {
            $this->assertStringNotContainsString(self::PASSWORD, $contents, "password found in {$where}");
            $this->assertStringNotContainsString(self::TYPED_PASSWORD, $contents, "typed password found in {$where}");
            $this->assertDoesNotMatchRegularExpression('/\bLOGIN\s+"/', $contents, "an IMAP LOGIN line found in {$where}");
            if ($where !== 'settings' && $where !== 'cache') {
                $this->assertStringNotContainsString(self::USERNAME, $contents, "username found in {$where}");
            }
        }
        // The settings store holds the configured username by design; nothing else in the cache does.
        $this->assertStringNotContainsString(self::USERNAME, str_replace($this->settingsCache(), '', $places['cache']));
        $this->assertStringNotContainsString(self::SUBJECT, $places['connection log']);
        $this->assertGreaterThan(0, DB::table('inbound_mailbox_log')->count());
    }

    public function test_only_the_newest_50_entries_are_kept_and_shown(): void
    {
        foreach (range(1, 60) as $n) {
            InboundMailboxLogEntry::recordPoll(['result' => InboundMailPoller::RESULT_POLLED, 'fetched' => $n, 'stored' => 0, 'duplicates' => 0, 'failed' => 0], 12);
        }

        $this->assertSame(50, InboundMailboxLogEntry::query()->count());
        $this->assertSame(60, InboundMailboxLogEntry::recent()->first()->fetched);
        $this->assertSame(11, InboundMailboxLogEntry::recent()->last()->fetched);

        $this->actingAs($this->admin)->get(route('admin.emails.import.index'))
            ->assertSee(__('admin.emails.import.log.heading'))
            ->assertSee(__('admin.emails.import.log.counts_value', ['fetched' => 60, 'stored' => 0, 'duplicates' => 0, 'failed' => 0]))
            ->assertDontSee(__('admin.emails.import.log.counts_value', ['fetched' => 10, 'stored' => 0, 'duplicates' => 0, 'failed' => 0]));
    }

    public function test_entries_older_than_30_days_are_pruned(): void
    {
        InboundMailboxLogEntry::recordPoll(['result' => InboundMailPoller::RESULT_POLLED, 'fetched' => 1, 'stored' => 1, 'duplicates' => 0, 'failed' => 0], 5);
        $this->travel(31)->days();
        InboundMailboxLogEntry::recordPoll(['result' => InboundMailPoller::RESULT_POLLED, 'fetched' => 2, 'stored' => 2, 'duplicates' => 0, 'failed' => 0], 5);

        $this->artisan('inbound-mail:prune')->expectsOutputToContain('Pruned 1 connection log')->assertSuccessful();

        $this->assertSame([2], InboundMailboxLogEntry::query()->pluck('fetched')->all());
    }

    public function test_tests_and_polls_are_logged_with_their_step_type_and_counts(): void
    {
        config(['inbound-mail.imap.username' => self::USERNAME, 'inbound-mail.imap.password' => self::PASSWORD]);
        $this->server->folders = ['Archive'];
        $this->actingAs($this->admin)->put(route('admin.emails.import.test'), ['host' => 'imap.example.test', 'port' => '993', 'encryption' => 'ssl', 'username' => self::USERNAME, 'password' => '', 'folder' => 'INBOX']);
        $this->mailbox->add($this->fixture('plain-reply'));
        app(InboundMailPoller::class)->poll();

        [$poll, $test] = InboundMailboxLogEntry::recent()->all();
        $this->assertSame(['test', 'folder_not_found', 'folder'], [$test->kind, $test->result, $test->step]);
        $this->assertSame(['poll', 'polled', 1, 1], [$poll->kind, $poll->result, $poll->fetched, $poll->stored]);

        $this->actingAs($this->admin)->get(route('admin.emails.import.index'))
            ->assertSee(__('admin.emails.import.log.kinds.test'))
            ->assertSee(__('admin.emails.import.errors.folder_not_found.message'))
            ->assertSee(__('admin.emails.import.log.results.polled'));
    }

    private function cacheContents(): string
    {
        $store = Cache::getStore();
        $this->assertInstanceOf(ArrayStore::class, $store);

        return serialize((new ReflectionProperty(ArrayStore::class, 'storage'))->getValue($store));
    }

    private function settingsCache(): string
    {
        return serialize(Cache::get('app-settings:inbound_mailbox'));
    }
}
