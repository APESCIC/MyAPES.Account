<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\Inbound\ReplyMailboxStatus;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Mail\Inbound\FakeMailbox;
use Tests\Feature\Mail\Inbound\InboundMailTestHelpers;
use Tests\TestCase;

/**
 * Test connection, poll status and the plus-address probe (#736).
 */
class ReplyMailboxHealthTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private const PASSWORD = 'not-a-real-mail-app-password';

    private User $admin;

    private FakeMailbox $mailbox;

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
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
    }

    public function test_a_successful_connection_test_is_shown_and_audited(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.settings.reply-mailbox.test'))
            ->assertRedirect(route('admin.settings.show', 'reply-mailbox'))
            ->assertSessionHas('status', __('admin.settings.reply_mailbox.test_ok'));

        $this->assertTrue(app(ReplyMailboxStatus::class)->lastTest()['ok']);
        $audit = AuditLog::query()->where('event', 'admin.settings.mailbox_tested')->sole();
        $this->assertSame('ok', $audit->context['result']);

        $this->actingAs($this->admin)->get(route('admin.settings.show', 'reply-mailbox'))
            ->assertSee(__('admin.settings.reply_mailbox.last_test_ok', ['at' => now()->format('Y-m-d H:i')]));
    }

    public function test_a_failed_connection_test_shows_the_failure_without_secrets(): void
    {
        $this->mailbox->unavailable = true;

        $this->actingAs($this->admin)
            ->post(route('admin.settings.reply-mailbox.test'))
            ->assertSessionHasErrors('mailbox');

        $this->assertFalse(app(ReplyMailboxStatus::class)->lastTest()['ok']);
        $audit = AuditLog::query()->where('event', 'admin.settings.mailbox_tested')->sole();
        $this->assertSame('failed', $audit->context['result']);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($audit->context));

        $this->actingAs($this->admin)->get(route('admin.settings.show', 'reply-mailbox'))
            ->assertSee('ConnectionFailedException')
            ->assertDontSee(self::PASSWORD);
    }

    public function test_testing_needs_the_mailbox_to_be_set_up(): void
    {
        config(['inbound-mail.imap.host' => null]);

        $this->actingAs($this->admin)
            ->post(route('admin.settings.reply-mailbox.test'))
            ->assertSessionHasErrors(['mailbox' => __('admin.settings.reply_mailbox.test_not_configured')]);
    }

    public function test_people_without_admin_settings_manage_are_forbidden(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)->post(route('admin.settings.reply-mailbox.test'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.settings.reply-mailbox.probe'))->assertForbidden();
    }

    public function test_the_last_successful_poll_is_shown(): void
    {
        app(InboundMailPoller::class)->poll();

        $this->assertNotNull(app(ReplyMailboxStatus::class)->lastSuccessfulPollAt());
        $this->actingAs($this->admin)->get(route('admin.settings.show', 'reply-mailbox'))
            ->assertSeeInOrder([__('admin.settings.reply_mailbox.last_successful_poll'), now()->format('Y-m-d H:i')]);
    }

    public function test_the_probe_reports_arrival_and_is_never_posted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.settings.reply-mailbox.probe'))
            ->assertSessionHas('status', __('admin.settings.reply_mailbox.probe_sent'));

        $sent = $this->sentEmails();
        $this->assertCount(1, $sent);
        $probeAddress = $sent[0]->getTo()[0]->getAddress();
        $this->assertMatchesRegularExpression('/^communications\+probe-[a-z0-9]{12}@example\.test$/', $probeAddress);
        $this->actingAs($this->admin)->get(route('admin.settings.show', 'reply-mailbox'))->assertSee(__('admin.settings.reply_mailbox.probe_waiting'));

        $this->mailbox->add($this->fixture('plain-reply', ['TO' => $probeAddress, 'FROM' => 'no-reply@example.test']));
        app(InboundMailPoller::class)->poll();

        $email = InboundEmail::query()->sole();
        $this->assertSame(InboundEmail::STATUS_IGNORED, $email->status);
        $this->assertSame('probe', $email->reason);
        $this->assertNull($email->posted_id);
        $this->assertNotNull(app(ReplyMailboxStatus::class)->probe()['arrived_at']);
        $this->actingAs($this->admin)->get(route('admin.settings.show', 'reply-mailbox'))->assertSee('so plus-addressing is delivered');
    }
}
