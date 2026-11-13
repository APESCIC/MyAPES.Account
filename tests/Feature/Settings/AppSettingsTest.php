<?php

namespace Tests\Feature\Settings;

use App\Core\Mail\Inbound\InboundMailboxSettings;
use App\Core\Settings\AppSetting;
use App\Core\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The shared admin settings store (#732).
 */
class AppSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = InboundMailboxSettings::GROUP;

    private const SECRET = 'not-a-real-mailbox-password';

    private AppSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null)]);
        $this->settings = app(AppSettings::class);
    }

    public function test_a_setting_falls_back_to_its_default(): void
    {
        $this->assertSame(993, $this->settings->get(self::GROUP, 'port'));
        $this->assertSame('mail.cloudron.apes.org.uk', $this->settings->get(self::GROUP, 'host'));
        $this->assertSame(AppSettings::SOURCE_DEFAULT, $this->settings->source(self::GROUP, 'port'));
    }

    public function test_a_stored_value_is_used_when_the_env_is_empty(): void
    {
        $this->settings->set(self::GROUP, ['host' => 'imap.example.test', 'port' => '143', 'validate_cert' => '0']);

        $this->assertSame('imap.example.test', $this->settings->get(self::GROUP, 'host'));
        $this->assertSame(143, $this->settings->get(self::GROUP, 'port'));
        $this->assertFalse($this->settings->get(self::GROUP, 'validate_cert'));
        $this->assertSame(AppSettings::SOURCE_DATABASE, $this->settings->source(self::GROUP, 'host'));
    }

    public function test_a_non_empty_env_value_wins_over_the_stored_value(): void
    {
        $this->settings->set(self::GROUP, ['host' => 'imap.example.test', 'plus_addressing' => '1']);
        config([
            'app-settings.env.INBOUND_MAIL_IMAP_HOST' => 'env-imap.example.test',
            'app-settings.env.INBOUND_MAIL_PLUS_ADDRESSING' => false,
        ]);

        $this->assertSame('env-imap.example.test', $this->settings->get(self::GROUP, 'host'));
        $this->assertFalse($this->settings->get(self::GROUP, 'plus_addressing'));
        $this->assertTrue($this->settings->overriddenByEnv(self::GROUP, 'host'));

        config(['app-settings.env.INBOUND_MAIL_IMAP_HOST' => '']);
        $this->assertSame('imap.example.test', $this->settings->get(self::GROUP, 'host'));
    }

    public function test_a_secret_is_encrypted_in_the_database_and_the_cache(): void
    {
        $this->settings->set(self::GROUP, ['password' => self::SECRET]);

        $raw = (string) DB::table('app_settings')->where('key', 'password')->value('value');
        $this->assertNotSame(self::SECRET, $raw);
        $this->assertStringNotContainsString(self::SECRET, $raw);
        $this->assertSame(self::SECRET, Crypt::decryptString($raw));
        $this->assertTrue((bool) DB::table('app_settings')->where('key', 'password')->value('is_secret'));

        $this->assertSame(self::SECRET, $this->settings->get(self::GROUP, 'password'));
        $this->assertStringNotContainsString(self::SECRET, serialize(Cache::get('app-settings:'.self::GROUP)));
        $this->assertArrayNotHasKey('value', AppSetting::query()->firstOrFail()->toArray());
    }

    public function test_a_blank_secret_keeps_the_stored_one_and_a_blank_value_clears_a_setting(): void
    {
        $this->settings->set(self::GROUP, ['password' => self::SECRET, 'host' => 'imap.example.test']);

        $changed = $this->settings->set(self::GROUP, ['password' => '', 'host' => '']);

        $this->assertSame(['host'], $changed);
        $this->assertSame(self::SECRET, $this->settings->get(self::GROUP, 'password'));
        $this->assertFalse($this->settings->stored(self::GROUP, 'host'));
        $this->assertSame('mail.cloudron.apes.org.uk', $this->settings->get(self::GROUP, 'host'));
        $this->assertTrue($this->settings->stored(self::GROUP, 'password'));
    }

    public function test_saving_clears_the_cache_and_reports_only_changes(): void
    {
        $this->settings->set(self::GROUP, ['folder' => 'INBOX']);
        $this->assertSame('INBOX', $this->settings->get(self::GROUP, 'folder'));

        $this->assertSame([], $this->settings->set(self::GROUP, ['folder' => 'INBOX']));
        $this->assertSame(['folder'], $this->settings->set(self::GROUP, ['folder' => 'Replies']));
        $this->assertSame('Replies', $this->settings->get(self::GROUP, 'folder'));
    }

    public function test_values_are_validated(): void
    {
        $this->expectException(ValidationException::class);

        $this->settings->set(self::GROUP, ['port' => '70000', 'encryption' => 'starttls']);
    }

    public function test_unknown_settings_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings->get(self::GROUP, 'nope');
    }

    public function test_secrets_never_reach_the_log(): void
    {
        $this->settings->set(self::GROUP, ['password' => self::SECRET]);
        DB::table('app_settings')->where('key', 'password')->update(['value' => 'not-ciphertext']);
        $this->settings->flush(self::GROUP);
        Log::spy();

        $this->assertNull($this->settings->get(self::GROUP, 'password'));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context === ['setting' => self::GROUP.'.password']
            && ! str_contains($message.json_encode($context), self::SECRET));
    }
}
