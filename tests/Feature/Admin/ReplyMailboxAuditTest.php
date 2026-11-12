<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\Diagnostics\MailboxTransport;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeImapServer;
use Tests\Feature\Mail\Inbound\FakeMailbox;
use Tests\TestCase;

/**
 * Reply mailbox changes and tests are audited without secrets (#740).
 */
class ReplyMailboxAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'not-a-real-mail-app-password';

    private const TOKEN_KEY = 'not-a-real-inbound-token-key';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null),
            'inbound-mail.token_key' => self::TOKEN_KEY,
        ]);
        $this->app->instance(InboundMailbox::class, new FakeMailbox);
        $this->app->instance(MailboxTransport::class, new FakeImapServer);
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
    }

    public function test_saving_records_the_changed_fields_and_never_the_password(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.reply-mailbox.update'), $this->form());

        $audit = AuditLog::query()->where('event', 'admin.settings.mailbox_saved')->sole();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertContains('host', $audit->context['changed']);
        $this->assertNotContains('password', $audit->context['changed']);
        $this->assertTrue($audit->context['password_changed']);
        $this->assertNoSecrets($audit);
    }

    public function test_saving_without_changes_records_nothing(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.reply-mailbox.update'), $this->form());
        $this->actingAs($this->admin)->put(route('admin.settings.reply-mailbox.update'), $this->form(['password' => '']));

        $this->assertSame(1, AuditLog::query()->where('event', 'admin.settings.mailbox_saved')->count());
    }

    public function test_keeping_the_password_records_that_it_did_not_change(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.reply-mailbox.update'), $this->form());
        $this->actingAs($this->admin)->put(route('admin.settings.reply-mailbox.update'), $this->form(['password' => '', 'folder' => 'Replies']));

        $audit = AuditLog::query()->where('event', 'admin.settings.mailbox_saved')->latest('id')->first();
        $this->assertSame(['folder'], $audit->context['changed']);
        $this->assertFalse($audit->context['password_changed']);
    }

    public function test_connection_tests_and_probes_are_audited_without_secrets(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.reply-mailbox.update'), $this->form());

        $this->actingAs($this->admin)->post(route('admin.settings.reply-mailbox.test'));
        $this->actingAs($this->admin)->post(route('admin.settings.reply-mailbox.probe'));

        foreach (['admin.emails.import_tested', 'admin.settings.mailbox_probe_sent'] as $event) {
            $this->assertNoSecrets(AuditLog::query()->where('event', $event)->sole());
        }
    }

    private function assertNoSecrets(AuditLog $audit): void
    {
        $recorded = json_encode($audit->toArray());

        $this->assertStringNotContainsString(self::PASSWORD, $recorded);
        $this->assertStringNotContainsString(self::TOKEN_KEY, $recorded);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        return [
            'enabled' => '1',
            'address' => 'communications@example.test',
            'host' => 'imap.example.test',
            'port' => '993',
            'encryption' => 'ssl',
            'validate_cert' => '1',
            'username' => 'communications@example.test',
            'password' => self::PASSWORD,
            'folder' => 'INBOX',
            'plus_addressing' => '1',
            ...$overrides,
        ];
    }
}
