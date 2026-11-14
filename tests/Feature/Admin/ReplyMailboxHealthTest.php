<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\Diagnostics\MailboxTransport;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\Inbound\ReplyMailboxStatus;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeImapServer;
use Tests\Feature\Mail\Inbound\FakeMailbox;
use Tests\Feature\Mail\Inbound\InboundMailTestHelpers;
use Tests\TestCase;

/**
 * Test connection (#736, #771), poll status and the plus-address probe (#736).
 */
class ReplyMailboxHealthTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private const PASSWORD = 'not-a-real-mail-app-password';

    private const TYPED_PASSWORD = 'typed-only-for-this-test-7f3k';

    private User $admin;

    private FakeMailbox $mailbox;

    private FakeImapServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->enableEmailReplies([
            'inbound-mail.imap.host' => 'imap.example.test',
            'inbound-mail.imap.username' => 'communications@example.test',
            'inbound-mail.imap.password' => self::PASSWORD,
        ]);
        $this->mailbox = new FakeMailbox;
        $this->app->instance(InboundMailbox::class, $this->mailbox);
        $this->server = new FakeImapServer;
        $this->app->instance(MailboxTransport::class, $this->server);
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
    }

    public function test_a_successful_connection_test_is_shown_and_audited(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.emails.import.test'))
            ->assertRedirect(route('admin.emails.import.index'))
            ->assertSessionHas('status', __('admin.emails.import.test_ok'));

        $this->assertTrue(app(ReplyMailboxStatus::class)->lastTest()['ok']);
        $audit = AuditLog::query()->where('event', 'admin.emails.import_tested')->sole();
        $this->assertSame(['result' => 'ok', 'step' => null, 'host' => 'imap.example.test'], $audit->context);

        $this->actingAs($this->admin)->get(route('admin.emails.import.index'))
            ->assertSee(__('admin.emails.import.last_test_ok', ['at' => now()->format('Y-m-d H:i')]));
    }

    public function test_each_step_is_shown_with_its_status_and_time(): void
    {
        $this->server->loginResponse = 'NO [AUTHENTICATIONFAILED] Authentication failed.';

        $this->actingAs($this->admin)
            ->followingRedirects()
            ->post(route('admin.emails.import.test'))
            ->assertSee('data-step="dns" data-step-status="passed"', false)
            ->assertSee('data-step="tcp" data-step-status="passed"', false)
            ->assertSee('data-step="tls" data-step-status="passed"', false)
            ->assertSee('data-step="login" data-step-status="failed"', false)
            ->assertSee('data-step="folder" data-step-status="skipped"', false)
            ->assertSee(__('admin.emails.import.steps.login'))
            ->assertSee(' ms)');
    }

    public function test_a_failed_connection_test_names_the_step_and_type_without_secrets(): void
    {
        $this->server->loginResponse = 'NO [AUTHENTICATIONFAILED] Authentication failed.';

        $this->actingAs($this->admin)
            ->post(route('admin.emails.import.test'))
            ->assertSessionHasErrors(['mailbox' => __('admin.emails.import.test_failed', [
                'step' => __('admin.emails.import.steps.login'),
                'reason' => __('admin.emails.import.errors.login_failed.message'),
            ])]);

        $this->assertFalse(app(ReplyMailboxStatus::class)->lastTest()['ok']);
        $audit = AuditLog::query()->where('event', 'admin.emails.import_tested')->sole();
        $this->assertSame(['result' => 'login_failed', 'step' => 'login', 'host' => 'imap.example.test'], $audit->context);

        $this->actingAs($this->admin)->get(route('admin.emails.import.index'))
            ->assertSee(__('admin.emails.import.errors.login_failed.message'))
            ->assertDontSee(self::PASSWORD);
    }

    public function test_the_built_in_connection_can_be_tested_without_saving_anything(): void
    {
        $before = DB::table('app_settings')->get()->toArray();

        $this->actingAs($this->admin)
            ->followingRedirects()
            ->post(route('admin.emails.import.test'))
            ->assertSee(__('admin.emails.import.test_ok'));

        $this->assertEquals($before, DB::table('app_settings')->get()->toArray());
        $this->assertContains('LOGIN', $this->server->commands);
    }

    public function test_a_failed_test_never_echoes_the_saved_password(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });
        $this->server->loginResponse = 'NO [AUTHENTICATIONFAILED] Authentication failed.';

        $response = $this->actingAs($this->admin)->post(route('admin.emails.import.test'));

        $this->assertStringNotContainsString(self::PASSWORD, json_encode(session()->all()));
        $this->actingAs($this->admin)->get($response->headers->get('Location'))->assertOk()->assertDontSee(self::PASSWORD);
        $this->assertStringNotContainsString(self::PASSWORD, implode("\n", $logged));
        $this->assertStringNotContainsString(self::PASSWORD, json_encode(Cache::get('inbound-mail:last-test')));
        $this->assertStringNotContainsString(self::PASSWORD, json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_the_sixth_test_inside_ten_minutes_is_refused(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->admin)->post(route('admin.emails.import.test'))->assertSessionHasNoErrors();
        }

        $this->actingAs($this->admin)
            ->post(route('admin.emails.import.test'))
            ->assertSessionHasErrors(['mailbox' => __('admin.emails.import.test_limited', ['count' => 5, 'minutes' => 10])]);

        $this->assertSame(5, AuditLog::query()->where('event', 'admin.emails.import_tested')->count());
    }

    public function test_people_without_admin_settings_manage_are_forbidden(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)->post(route('admin.emails.import.test'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.emails.import.probe'))->assertForbidden();
    }

    public function test_the_last_successful_poll_is_shown(): void
    {
        app(InboundMailPoller::class)->poll();

        $this->assertNotNull(app(ReplyMailboxStatus::class)->lastSuccessfulPollAt());
        $this->actingAs($this->admin)->get(route('admin.emails.import.index'))
            ->assertSeeInOrder([__('admin.emails.import.last_successful_poll'), now()->format('Y-m-d H:i')]);
    }

    public function test_the_probe_reports_arrival_and_is_never_posted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.emails.import.probe'))
            ->assertSessionHas('status', __('admin.emails.import.probe_sent'));

        $sent = $this->sentEmails();
        $this->assertCount(1, $sent);
        $probeAddress = $sent[0]->getTo()[0]->getAddress();
        $this->assertMatchesRegularExpression('/^communications\+probe-[a-z0-9]{12}@example\.test$/', $probeAddress);
        $this->actingAs($this->admin)->get(route('admin.emails.import.index'))->assertSee(__('admin.emails.import.probe_waiting'));

        $this->mailbox->add($this->fixture('plain-reply', ['TO' => $probeAddress, 'FROM' => 'no-reply@example.test']));
        app(InboundMailPoller::class)->poll();

        $email = InboundEmail::query()->sole();
        $this->assertSame(InboundEmail::STATUS_IGNORED, $email->status);
        $this->assertSame('probe', $email->reason);
        $this->assertNull($email->posted_id);
        $this->assertNotNull(app(ReplyMailboxStatus::class)->probe()['arrived_at']);
        $this->actingAs($this->admin)->get(route('admin.emails.import.index'))->assertSee('so plus-addressing is delivered');
    }

    public function test_the_probe_reports_not_arrived_after_ten_minutes(): void
    {
        $this->actingAs($this->admin)->post(route('admin.emails.import.probe'));
        $this->travel(11)->minutes();

        $this->actingAs($this->admin)->get(route('admin.emails.import.index'))
            ->assertSee(__('admin.emails.import.probe_not_arrived', ['minutes' => 10]));
    }

    public function test_fetch_now_cannot_overlap_a_scheduled_poll(): void
    {
        Cache::lock('inbound-mail:poll-lock', 60)->get();

        $this->actingAs($this->admin)
            ->post(route('admin.emails.import.fetch'))
            ->assertSessionHasErrors(['mailbox' => __('admin.emails.import.fetch_busy')]);
    }

    public function test_fetch_now_needs_emails_manage(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $this->actingAs($staff)->post(route('admin.emails.import.fetch'))->assertForbidden();
    }
}
