<?php

namespace Tests\Feature\Push;

use App\Core\Push\VapidCredentials;
use App\Core\Secrets\SecretRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VapidCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null),
        ]);
    }

    public function test_reports_not_configured_without_credentials(): void
    {
        $vapid = app(VapidCredentials::class);

        $this->assertFalse($vapid->isConfigured());
        $this->assertSame(VapidCredentials::STATUS_NOT_CONFIGURED, $vapid->statusLabel());
        $this->assertSame(SecretRegistry::SOURCE_MISSING, $vapid->source());
    }

    public function test_env_credentials_configure_push(): void
    {
        $this->applyEnvCredentials();
        $vapid = app(VapidCredentials::class);

        $this->assertTrue($vapid->isConfigured());
        $this->assertSame(VapidCredentials::STATUS_CONFIGURED, $vapid->statusLabel());
        $this->assertSame(SecretRegistry::SOURCE_ENV, $vapid->source());
        $this->assertSame(VapidTestKeys::PUBLIC, $vapid->publicKey());
        $this->assertSame(VapidTestKeys::PRIVATE, $vapid->privateKey());
        $this->assertSame(VapidTestKeys::SUBJECT, $vapid->subject());
    }

    public function test_keys_and_secrets_win_over_env_and_clearing_falls_back(): void
    {
        $this->applyEnvCredentials();
        $secrets = app(SecretRegistry::class);
        $secrets->set(SecretRegistry::VAPID_PUBLIC_KEY, 'keys-public');
        $secrets->set(SecretRegistry::VAPID_PRIVATE_KEY, 'keys-private');
        $secrets->set(SecretRegistry::VAPID_SUBJECT, 'mailto:keys@example.test');

        $vapid = app(VapidCredentials::class);
        $this->assertSame('keys-public', $vapid->publicKey());
        $this->assertSame('keys-private', $vapid->privateKey());
        $this->assertSame(SecretRegistry::SOURCE_KEYS, $vapid->source());

        $secrets->clear(SecretRegistry::VAPID_PUBLIC_KEY);
        $secrets->clear(SecretRegistry::VAPID_PRIVATE_KEY);
        $secrets->clear(SecretRegistry::VAPID_SUBJECT);

        $this->assertSame(VapidTestKeys::PUBLIC, $vapid->publicKey());
        $this->assertSame(VapidTestKeys::PRIVATE, $vapid->privateKey());
        $this->assertSame(SecretRegistry::SOURCE_ENV, $vapid->source());
    }

    private function applyEnvCredentials(): void
    {
        config([
            'app-settings.env.VAPID_PUBLIC_KEY' => VapidTestKeys::PUBLIC,
            'app-settings.env.VAPID_PRIVATE_KEY' => VapidTestKeys::PRIVATE,
            'app-settings.env.VAPID_SUBJECT' => VapidTestKeys::SUBJECT,
        ]);
    }
}
