<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Mail\Inbound\InboundMailboxSettings;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * InboundMailSettings reads .env first, then Admin → Settings (#737).
 */
class InboundMailSettingsResolveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null),
            'inbound-mail.enabled' => false,
            'inbound-mail.token_key' => 'inbound-test-key',
            'inbound-mail.plus_addressing' => true,
            'inbound-mail.imap.host' => null,
            'inbound-mail.imap.username' => null,
            'inbound-mail.imap.password' => null,
        ]);
    }

    public function test_replies_stay_off_with_neither_env_nor_saved_settings(): void
    {
        $settings = app(InboundMailSettings::class);

        $this->assertFalse($settings->repliesEnabled());
        $this->assertFalse($settings->pollingConfigured());
    }

    public function test_env_only(): void
    {
        $this->env([
            'INBOUND_MAIL_ENABLED' => [true, 'inbound-mail.enabled'],
            'INBOUND_MAIL_IMAP_HOST' => ['env-imap.example.test', 'inbound-mail.imap.host'],
            'INBOUND_MAIL_IMAP_USERNAME' => ['env-user', 'inbound-mail.imap.username'],
            'INBOUND_MAIL_IMAP_PASSWORD' => ['env-password', 'inbound-mail.imap.password'],
        ]);
        $settings = app(InboundMailSettings::class);

        $this->assertTrue($settings->pollingConfigured());
        $this->assertSame('env-imap.example.test', $settings->imap('host'));
    }

    public function test_database_only(): void
    {
        $this->save([
            'enabled' => true,
            'address' => 'Replies@Example.test',
            'host' => 'db-imap.example.test',
            'port' => 143,
            'validate_cert' => false,
            'username' => 'db-user',
            'password' => 'db-password',
            'folder' => 'Replies',
        ]);
        $settings = app(InboundMailSettings::class);

        $this->assertTrue($settings->repliesEnabled());
        $this->assertTrue($settings->pollingConfigured());
        $this->assertSame('replies@example.test', $settings->address());
        $this->assertSame('db-imap.example.test', $settings->imap('host'));
        $this->assertSame('143', $settings->imap('port'));
        $this->assertSame('db-password', $settings->imap('password'));
        $this->assertSame('Replies', $settings->imap('folder'));
        $this->assertFalse($settings->validateCert());
        $this->assertSame((string) config('inbound-mail.imap.timeout'), $settings->imap('timeout'));
    }

    public function test_env_overrides_the_database(): void
    {
        $this->save(['enabled' => true, 'host' => 'db-imap.example.test', 'username' => 'db-user', 'password' => 'db-password']);
        $this->env(['INBOUND_MAIL_IMAP_HOST' => ['env-imap.example.test', 'inbound-mail.imap.host']]);
        $settings = app(InboundMailSettings::class);

        $this->assertSame('env-imap.example.test', $settings->imap('host'));
        $this->assertSame('db-user', $settings->imap('username'));
    }

    public function test_plus_addressing_resolves_env_first_then_the_database(): void
    {
        $this->save(['plus_addressing' => false]);
        $settings = app(InboundMailSettings::class);
        $this->assertFalse($settings->plusAddressing());

        $this->env(['INBOUND_MAIL_PLUS_ADDRESSING' => [true, 'inbound-mail.plus_addressing']]);
        $this->assertTrue($settings->plusAddressing());
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function save(array $values): void
    {
        app(AppSettings::class)->set(InboundMailboxSettings::GROUP, $values);
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
