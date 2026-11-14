<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\Diagnostics\MailboxTransport;
use App\Core\Secrets\SecretRegistry;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeImapServer;
use Tests\Feature\Mail\Inbound\FakeMailbox;
use Tests\TestCase;

/**
 * Import tests and probes are audited without secrets (#740, #809).
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
            'inbound-mail.enabled' => null,
            'inbound-mail.token_key' => self::TOKEN_KEY,
            'inbound-mail.address' => 'communications@example.test',
            'inbound-mail.imap.host' => 'imap.example.test',
            'inbound-mail.imap.port' => 993,
            'inbound-mail.imap.encryption' => 'ssl',
        ]);
        app(SecretRegistry::class)->set(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD, self::PASSWORD);
        $this->app->instance(InboundMailbox::class, new FakeMailbox);
        $this->app->instance(MailboxTransport::class, new FakeImapServer);
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
    }

    public function test_connection_tests_and_probes_are_audited_without_secrets(): void
    {
        $this->actingAs($this->admin)->post(route('admin.emails.import.test'));
        $this->actingAs($this->admin)->post(route('admin.emails.import.probe'));

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
}
