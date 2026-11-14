<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\InboundMailboxSettings;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\Inbound\RetireStoredMailboxSettings;
use App\Core\Secrets\SecretRegistry;
use App\Core\Settings\AppSetting;
use App\Core\Settings\AppSettings;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Mail\Inbound\FakeMailbox;
use Tests\Feature\Mail\Inbound\InboundMailTestHelpers;
use Tests\TestCase;

/**
 * Retiring the Import settings form and old stored mailbox values (#809).
 */
class RetireStoredMailboxSettingsTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Queue::fake();
        config([
            'app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null),
            'inbound-mail.enabled' => null,
            'inbound-mail.token_key' => 'inbound-test-key',
            'inbound-mail.address' => 'communications@example.test',
            'inbound-mail.imap.host' => 'mail.cloudron.apes.org.uk',
            'inbound-mail.imap.port' => 993,
            'inbound-mail.imap.encryption' => 'ssl',
        ]);
    }

    public function test_no_route_accepts_mailbox_connection_values_other_than_keys_and_secrets(): void
    {
        $this->assertFalse(Route::has('admin.emails.import.update'));

        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $this->actingAs($admin)->put('/admin/emails/import', [
            'host' => 'evil.example.test',
            'password' => 'should-not-save',
        ])->assertMethodNotAllowed();

        $this->assertSame(0, AppSetting::query()->where('group', InboundMailboxSettings::GROUP)->count());
    }

    public function test_old_rows_are_deleted_only_after_a_successful_poll_on_the_new_source(): void
    {
        app(AppSettings::class)->set(InboundMailboxSettings::GROUP, [
            'enabled' => true,
            'host' => 'legacy.example.test',
            'username' => 'legacy-user',
            'password' => 'legacy-password',
            'folder' => 'INBOX',
        ]);
        $this->assertTrue(app(RetireStoredMailboxSettings::class)->hasStoredRows());

        $this->artisan('inbound-mail:retire-stored-settings')->assertFailed();
        $this->assertTrue(app(RetireStoredMailboxSettings::class)->hasStoredRows());

        app(SecretRegistry::class)->set(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD, 'keys-password');
        $mailbox = new FakeMailbox;
        $mailbox->add($this->fixture('plain-reply', ['TO' => 'communications@example.test']));
        $this->app->instance(InboundMailbox::class, $mailbox);

        $this->assertSame(InboundMailPoller::RESULT_POLLED, app(InboundMailPoller::class)->poll()['result']);
        $this->assertFalse(app(RetireStoredMailboxSettings::class)->hasStoredRows());

        $audit = AuditLog::query()->where('event', 'admin.settings.mailbox_retired')->sole();
        $this->assertEqualsCanonicalizing(['enabled', 'host', 'username', 'password', 'folder'], $audit->context['fields'] ?? []);
        $this->assertStringNotContainsString('legacy-password', json_encode($audit->context));
        $this->assertStringNotContainsString('keys-password', json_encode($audit->context));
    }

    public function test_artisan_retire_refuses_while_no_new_password_exists_and_works_when_it_does(): void
    {
        app(AppSettings::class)->set(InboundMailboxSettings::GROUP, [
            'password' => 'legacy-password',
            'host' => 'legacy.example.test',
        ]);

        $this->artisan('inbound-mail:retire-stored-settings')->assertFailed();

        config(['app-settings.env.INBOUND_MAIL_IMAP_PASSWORD' => 'env-password']);
        $this->artisan('inbound-mail:retire-stored-settings')
            ->expectsOutputToContain('Retired stored mailbox settings')
            ->assertSuccessful();

        $this->assertFalse(app(RetireStoredMailboxSettings::class)->hasStoredRows());
    }

    public function test_old_settings_url_still_redirects_with_301(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($admin)->get('/admin/settings/reply-mailbox')
            ->assertRedirect(route('admin.emails.import.index'))
            ->assertStatus(301);
    }
}
