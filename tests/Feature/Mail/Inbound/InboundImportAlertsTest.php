<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\Diagnostics\FailureType;
use App\Core\Mail\Inbound\ImportAlerts;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\Inbound\ReplyMailboxStatus;
use App\Notifications\InboundImportFailingNotification;
use App\Notifications\InboundImportRecoveredNotification;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Import heartbeat, failure alerts and the healthz field (#779).
 */
class InboundImportAlertsTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private const PASSWORD = 'not-a-real-password';

    private FakeMailbox $mailbox;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Notification::fake();
        $this->enableEmailReplies([
            'inbound-mail.imap.host' => 'imap.example.test',
            'inbound-mail.imap.username' => 'communications@example.test',
            'inbound-mail.imap.password' => self::PASSWORD,
        ]);
        $this->mailbox = new FakeMailbox;
        $this->app->instance(InboundMailbox::class, $this->mailbox);
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
    }

    public function test_each_poll_records_its_result_type_failure_count_and_heartbeat(): void
    {
        $this->mailbox->unavailable = true;
        $this->artisan('tickets:inbound-poll')->assertSuccessful();

        $status = app(ReplyMailboxStatus::class);
        $this->assertSame(1, $status->consecutiveFailures());
        $this->assertSame(InboundMailPoller::RESULT_UNAVAILABLE, $status->lastPoll()['result']);
        $this->assertNotNull($status->failingSince());
        $this->assertTrue($status->heartbeatAt()?->isToday());
    }

    public function test_one_email_per_incident_not_per_poll(): void
    {
        $this->failPolls(2);
        Notification::assertNothingSent();

        $this->failPolls(1);
        Notification::assertSentTo($this->admin, InboundImportFailingNotification::class);
        Notification::assertSentTimes(InboundImportFailingNotification::class, 1);

        $this->failPolls(3);
        Notification::assertSentTimes(InboundImportFailingNotification::class, 1);
    }

    public function test_fifteen_minutes_of_failures_opens_an_incident_too(): void
    {
        $this->failPolls(1);
        $this->travel(16)->minutes();

        $this->failPolls(1);

        $this->assertNotNull(app(ImportAlerts::class)->incident());
        Notification::assertSentTimes(InboundImportFailingNotification::class, 1);
    }

    public function test_the_banner_appears_after_the_third_failure_and_clears_on_success(): void
    {
        $this->failPolls(2);
        $this->actingAs($this->admin)->get(route('admin.emails.inbound.index'))->assertDontSee('data-import-alert', false);

        $this->failPolls(1);
        $this->actingAs($this->admin)->get(route('admin.emails.inbound.index'))
            ->assertSee('data-import-alert', false)
            ->assertSee(__('admin.emails.import.alert.title'))
            ->assertSee(route('admin.emails.import.index'), false)
            ->assertDontSee(self::PASSWORD);

        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $this->actingAs($staff)->get(route('dashboard'))->assertDontSee('data-import-alert', false);

        $this->succeed();
        $this->actingAs($this->admin)->get(route('admin.emails.inbound.index'))->assertDontSee('data-import-alert', false);
    }

    public function test_a_recovery_email_is_sent_once(): void
    {
        $this->failPolls(3);

        $this->succeed();
        $this->succeed();

        Notification::assertSentTo($this->admin, InboundImportRecoveredNotification::class);
        Notification::assertSentTimes(InboundImportRecoveredNotification::class, 1);
    }

    public function test_suspended_and_non_admin_accounts_are_not_emailed(): void
    {
        $suspended = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create(['suspended_at' => now()]);
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->failPolls(3);

        Notification::assertNotSentTo([$suspended, $staff], InboundImportFailingNotification::class);
    }

    public function test_healthz_shows_only_ok_failing_or_off(): void
    {
        $this->get(route('health'))->assertJsonPath('checks.inbound_mail', 'ok');

        $this->failPolls(3);
        $failing = $this->get(route('health'))->assertOk()->assertJsonPath('checks.inbound_mail', 'failing');
        $this->assertStringNotContainsString('imap.example.test', (string) $failing->getContent());
        $this->assertStringNotContainsString('communications@', (string) $failing->getContent());
        $this->assertStringNotContainsString('connection_refused', (string) $failing->getContent());

        config(['inbound-mail.enabled' => false]);
        $this->get(route('health'))->assertJsonPath('checks.inbound_mail', 'off');
    }

    public function test_the_failing_email_names_the_failure_without_secrets(): void
    {
        $mail = (new InboundImportFailingNotification(FailureType::LoginFailed, 3))->toMail($this->admin);
        $html = $mail->render();

        $this->assertStringContainsString(e(__('admin.emails.import.errors.login_failed.message')), $html);
        $this->assertStringContainsString(route('admin.emails.import.index'), $html);
        $this->assertStringNotContainsString(self::PASSWORD, $html);
    }

    private function failPolls(int $times): void
    {
        $this->mailbox->unavailable = true;
        for ($i = 0; $i < $times; $i++) {
            $this->waitForNextPoll();
            app(InboundMailPoller::class)->poll();
        }
    }

    private function succeed(): void
    {
        $this->mailbox->unavailable = false;
        $this->waitForNextPoll();
        $this->assertSame(InboundMailPoller::RESULT_POLLED, app(InboundMailPoller::class)->poll()['result']);
    }

    private function waitForNextPoll(): void
    {
        $next = app(ReplyMailboxStatus::class)->nextPollAt();
        if ($next?->isFuture()) {
            $this->travelTo($next->addSecond());
        }
    }
}
