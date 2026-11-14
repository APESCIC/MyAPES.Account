<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Secrets\SecretRegistry;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Built-in mailbox connection after the Import form was retired (#809).
 */
class ReplyMailboxSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null),
            'inbound-mail.enabled' => null,
            'inbound-mail.token_key' => 'inbound-test-key',
            'inbound-mail.address' => 'communications@myapes.me.uk',
            'inbound-mail.imap.host' => 'mail.cloudron.apes.org.uk',
            'inbound-mail.imap.port' => 993,
            'inbound-mail.imap.encryption' => 'ssl',
            'inbound-mail.imap.folder' => 'INBOX',
        ]);
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
    }

    public function test_the_import_page_shows_the_built_in_connection_and_keys_link(): void
    {
        $this->actingAs($this->admin)->get(route('admin.emails.import.index'))
            ->assertOk()
            ->assertSee(__('admin.emails.import.connection_built_in'), false)
            ->assertSee(__('admin.emails.import.keys_and_secrets_link'), false)
            ->assertSee(route('admin.settings.show', 'keys-and-secrets'), false)
            ->assertDontSee('data-reply-mailbox-form', false)
            ->assertDontSee('name="host"', false);
    }

    public function test_the_update_route_is_gone_and_staff_cannot_post_connection_values(): void
    {
        $this->assertFalse(Route::has('admin.emails.import.update'));

        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $this->actingAs($staff)->post(route('admin.emails.import.test'))->assertForbidden();
        $this->actingAs($this->admin)->put('/admin/emails/import', ['host' => 'evil.test'])->assertMethodNotAllowed();
    }

    public function test_mismatched_port_and_encryption_from_env_is_refused_on_test(): void
    {
        app(SecretRegistry::class)->set(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD, 'keys-password');
        config([
            'inbound-mail.imap.port' => 993,
            'inbound-mail.imap.encryption' => 'tls',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.emails.import.test'))
            ->assertSessionHasErrors('mailbox');

        $this->assertNotNull(app(InboundMailSettings::class)->connectionMismatchMessage());
    }
}
