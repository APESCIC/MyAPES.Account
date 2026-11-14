<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Mail\Inbound\InboundMailboxSettings;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Secrets\SecretRegistry;
use App\Core\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * InboundMailSettings uses built-in config, Keys and secrets, then .env (#808).
 */
class InboundMailSettingsResolveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null),
            'inbound-mail.enabled' => null,
            'inbound-mail.address' => 'communications@myapes.me.uk',
            'inbound-mail.token_key' => 'inbound-test-key',
            'inbound-mail.plus_addressing' => true,
            'inbound-mail.imap.host' => 'mail.cloudron.apes.org.uk',
            'inbound-mail.imap.port' => 993,
            'inbound-mail.imap.encryption' => 'ssl',
            'inbound-mail.imap.validate_cert' => true,
            'inbound-mail.imap.username' => null,
            'inbound-mail.imap.password' => null,
            'inbound-mail.imap.folder' => 'INBOX',
            'inbound-mail.driver' => 'imap',
        ]);
    }

    public function test_replies_stay_off_without_a_password(): void
    {
        $settings = app(InboundMailSettings::class);

        $this->assertFalse($settings->repliesEnabled());
        $this->assertFalse($settings->pollingConfigured());
        $this->assertSame(InboundMailSettings::PASSWORD_SOURCE_MISSING, $settings->passwordSource());
    }

    public function test_keys_and_secrets_password_configures_the_built_in_mailbox(): void
    {
        app(SecretRegistry::class)->set(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD, 'keys-password');
        $settings = app(InboundMailSettings::class);

        $this->assertTrue($settings->pollingConfigured());
        $this->assertSame('mail.cloudron.apes.org.uk', $settings->imap('host'));
        $this->assertSame('993', $settings->imap('port'));
        $this->assertSame('ssl', $settings->imap('encryption'));
        $this->assertSame('communications@myapes.me.uk', $settings->address());
        $this->assertSame('communications@myapes.me.uk', $settings->imap('username'));
        $this->assertSame('keys-password', $settings->imap('password'));
        $this->assertSame(InboundMailSettings::PASSWORD_SOURCE_KEYS, $settings->passwordSource());
    }

    public function test_keys_and_secrets_password_wins_over_env_and_clearing_falls_back(): void
    {
        $this->env([
            'INBOUND_MAIL_IMAP_PASSWORD' => ['env-password', 'inbound-mail.imap.password'],
        ]);
        $secrets = app(SecretRegistry::class);
        $secrets->set(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD, 'keys-password');
        $settings = app(InboundMailSettings::class);

        $this->assertSame('keys-password', $settings->imap('password'));
        $this->assertSame(InboundMailSettings::PASSWORD_SOURCE_KEYS, $settings->passwordSource());

        $secrets->clear(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD);
        $this->assertSame('env-password', $settings->imap('password'));
        $this->assertSame(InboundMailSettings::PASSWORD_SOURCE_ENV, $settings->passwordSource());
        $this->assertTrue($settings->pollingConfigured());
    }

    public function test_neither_keys_nor_env_password_turns_polling_off(): void
    {
        $settings = app(InboundMailSettings::class);

        $this->assertSame(InboundMailSettings::PASSWORD_SOURCE_MISSING, $settings->passwordSource());
        $this->assertFalse($settings->pollingConfigured());
    }

    public function test_enabled_false_turns_replies_off_even_with_a_password(): void
    {
        app(SecretRegistry::class)->set(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD, 'keys-password');
        config(['inbound-mail.enabled' => false]);
        $settings = app(InboundMailSettings::class);

        $this->assertFalse($settings->repliesEnabled());
        $this->assertFalse($settings->pollingConfigured());
    }

    public function test_mismatched_port_and_encryption_in_env_is_refused(): void
    {
        app(SecretRegistry::class)->set(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD, 'keys-password');
        $this->env([
            'INBOUND_MAIL_IMAP_PORT' => [993, 'inbound-mail.imap.port'],
            'INBOUND_MAIL_IMAP_ENCRYPTION' => ['tls', 'inbound-mail.imap.encryption'],
        ]);
        $settings = app(InboundMailSettings::class);

        $this->assertNotNull($settings->connectionMismatchMessage());
        $this->assertFalse($settings->pollingConfigured());

        $this->env([
            'INBOUND_MAIL_IMAP_PORT' => [143, 'inbound-mail.imap.port'],
            'INBOUND_MAIL_IMAP_ENCRYPTION' => ['ssl', 'inbound-mail.imap.encryption'],
        ]);
        $this->assertNotNull($settings->connectionMismatchMessage());
        $this->assertFalse($settings->pollingConfigured());
    }

    public function test_non_empty_env_overrides_built_in_connection_values(): void
    {
        app(SecretRegistry::class)->set(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD, 'keys-password');
        $this->env([
            'INBOUND_MAIL_IMAP_HOST' => ['custom.imap.example.test', 'inbound-mail.imap.host'],
            'INBOUND_MAIL_IMAP_USERNAME' => ['custom-user', 'inbound-mail.imap.username'],
            'INBOUND_MAIL_ADDRESS' => ['replies@example.test', 'inbound-mail.address'],
        ]);
        $settings = app(InboundMailSettings::class);

        $this->assertSame('custom.imap.example.test', $settings->imap('host'));
        $this->assertSame('custom-user', $settings->imap('username'));
        $this->assertSame('replies@example.test', $settings->address());
    }

    public function test_legacy_database_password_keeps_import_running_until_retired(): void
    {
        app(AppSettings::class)->set(InboundMailboxSettings::GROUP, [
            'enabled' => true,
            'address' => 'Replies@Example.test',
            'host' => 'db-imap.example.test',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => 'db-user',
            'password' => 'db-password',
            'folder' => 'Replies',
        ]);
        $settings = app(InboundMailSettings::class);

        $this->assertSame(InboundMailSettings::PASSWORD_SOURCE_LEGACY, $settings->passwordSource());
        $this->assertTrue($settings->pollingConfigured());
        $this->assertSame('db-imap.example.test', $settings->imap('host'));
        $this->assertSame('db-password', $settings->imap('password'));
        $this->assertSame('replies@example.test', $settings->address());
    }

    /**
     * Mimics a value set in .env: config/app-settings.php captures it and the inbound-mail config reads it.
     *
     * @param  array<string, array{mixed, string}>  $variables
     */
    private function env(array $variables): void
    {
        foreach ($variables as $name => [$value, $configKey]) {
            config(['app-settings.env.'.$name => $value, $configKey => $value]);
        }
    }
}
