<?php

namespace Tests\Feature\Integrations;

use App\Core\Integrations\Integration;
use App\Core\Integrations\IntegrationField;
use App\Core\Integrations\IntegrationRegistry;
use App\Core\Settings\SettingDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The integrations registry on the shared settings store (#634).
 */
class IntegrationRegistryTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'not-a-real-secret-key-1234';

    private IntegrationRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app-settings.env.EXAMPLE_SECRET' => null, 'app-settings.env.EXAMPLE_SITE' => null]);

        $this->registry = app(IntegrationRegistry::class);
        $this->registry->register(new Integration('example', [
            new IntegrationField('site_key', env: 'EXAMPLE_SITE', rules: ['string', 'max:100']),
            new IntegrationField('secret_key', secret: true, env: 'EXAMPLE_SECRET', rules: ['string', 'max:200']),
            new IntegrationField('threshold', SettingDefinition::STRING, default: '0.5', rules: ['numeric'], required: false),
        ], static fn (IntegrationRegistry $registry): ?string => $registry->configured('example') ? null : 'not set up'));
    }

    public function test_a_secret_is_stored_encrypted(): void
    {
        $this->registry->save('example', ['site_key' => 'site', 'secret_key' => self::SECRET]);

        $raw = (string) DB::table('app_settings')->where('group', 'integration_example')->where('key', 'secret_key')->value('value');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString(self::SECRET, $raw);
        $this->assertSame(self::SECRET, $this->registry->value('example', 'secret_key'));
    }

    public function test_the_env_value_wins_when_it_is_set(): void
    {
        $this->registry->save('example', ['site_key' => 'from-database', 'secret_key' => self::SECRET]);
        $this->assertSame(IntegrationRegistry::STATUS_SET, $this->registry->status('example'));

        config(['app-settings.env.EXAMPLE_SITE' => 'from-env']);

        $this->assertSame('from-env', $this->registry->value('example', 'site_key'));
        $this->assertTrue($this->registry->overriddenByEnv('example', 'site_key'));
        $this->assertSame(IntegrationRegistry::STATUS_ENV, $this->registry->status('example'));
    }

    public function test_saving_skips_fields_set_by_env(): void
    {
        config(['app-settings.env.EXAMPLE_SITE' => 'from-env']);

        $this->assertSame(['secret_key'], $this->registry->save('example', ['site_key' => 'ignored', 'secret_key' => self::SECRET]));
        $this->assertDatabaseMissing('app_settings', ['group' => 'integration_example', 'key' => 'site_key']);
    }

    public function test_status_is_not_set_until_every_required_field_has_a_value(): void
    {
        $this->assertSame(IntegrationRegistry::STATUS_NOT_SET, $this->registry->status('example'));
        $this->assertSame('not set up', $this->registry->test('example'));

        $this->registry->save('example', ['site_key' => 'site']);
        $this->assertFalse($this->registry->configured('example'));

        $this->registry->save('example', ['secret_key' => self::SECRET]);
        $this->assertTrue($this->registry->configured('example'));
        $this->assertNull($this->registry->test('example'));
        $this->assertSame('0.5', $this->registry->value('example', 'threshold'));
    }

    public function test_a_blank_secret_keeps_the_saved_one(): void
    {
        $this->registry->save('example', ['secret_key' => self::SECRET]);

        $this->assertSame([], $this->registry->save('example', ['secret_key' => '']));
        $this->assertSame(self::SECRET, $this->registry->value('example', 'secret_key'));
    }

    public function test_the_secret_hint_is_only_the_last_four_characters(): void
    {
        $this->assertNull($this->registry->secretHint('example', 'secret_key'));

        $this->registry->save('example', ['secret_key' => self::SECRET]);
        $this->assertSame('1234', $this->registry->secretHint('example', 'secret_key'));

        $this->registry->save('example', ['secret_key' => 'short-key']);
        $this->assertNull($this->registry->secretHint('example', 'secret_key'));
    }

    public function test_unknown_integrations_and_fields_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry->value('example', 'nope');
    }
}
