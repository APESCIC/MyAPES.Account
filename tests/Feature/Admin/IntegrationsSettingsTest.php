<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Integrations\Integration;
use App\Core\Integrations\IntegrationField;
use App\Core\Integrations\IntegrationRegistry;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Admin → Settings → Integrations (#635).
 */
class IntegrationsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'not-a-real-integration-key-9876';

    private User $admin;

    private ?string $testResult = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null)]);
        app(IntegrationRegistry::class)->register(new Integration('example', [
            new IntegrationField('site_key', rules: ['string', 'max:100']),
            new IntegrationField('secret_key', secret: true, env: 'EXAMPLE_SECRET', rules: ['string', 'max:200']),
        ], fn (): ?string => $this->testResult));
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function deniedRoles(): array
    {
        return [
            'service user' => [AuthorizationProfile::ROLE_SERVICE_USER],
            'staff' => [AuthorizationProfile::ROLE_STAFF],
        ];
    }

    #[DataProvider('deniedRoles')]
    public function test_people_who_are_not_administrators_get_403(string $role): void
    {
        $user = User::factory()->protectedRole($role)->create();

        $this->actingAs($user)->get(route('admin.settings.show', 'integrations'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.settings.integrations.update', 'example'), ['secret_key' => self::SECRET])->assertForbidden();
        $this->actingAs($user)->post(route('admin.settings.integrations.test', 'example'))->assertForbidden();
        $this->assertNull(app(IntegrationRegistry::class)->value('example', 'secret_key'));
    }

    public function test_an_administrator_saves_a_key_and_it_never_appears_in_html_or_logs(): void
    {
        Log::spy();

        $this->actingAs($this->admin)
            ->put(route('admin.settings.integrations.update', 'example'), ['site_key' => 'public-site-key', 'secret_key' => self::SECRET])
            ->assertRedirect(route('admin.settings.show', 'integrations'));

        $this->assertSame(self::SECRET, app(IntegrationRegistry::class)->value('example', 'secret_key'));

        $page = $this->actingAs($this->admin)->get(route('admin.settings.show', 'integrations'))
            ->assertOk()
            ->assertSee('data-integration="example"', false)
            ->assertSee('data-integration-status="set"', false)
            ->assertSee('value="public-site-key"', false)
            ->assertSee(__('admin.settings.integrations.secret_saved_hint', ['hint' => '9876']))
            ->assertDontSee(self::SECRET);

        $this->assertStringNotContainsString(self::SECRET, (string) $page->getContent());
        foreach (['debug', 'info', 'notice', 'warning', 'error'] as $level) {
            Log::shouldNotHaveReceived($level, fn (...$arguments): bool => str_contains((string) json_encode($arguments), self::SECRET));
        }
    }

    public function test_saving_writes_an_audit_row_without_the_secret(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.integrations.update', 'example'), ['site_key' => 'public-site-key', 'secret_key' => self::SECRET]);

        $audit = AuditLog::query()->where('event', 'admin.settings.integration_saved')->firstOrFail();
        $this->assertSame(['integration' => 'example', 'changed' => ['site_key'], 'secrets_replaced' => ['secret_key']], $audit->context);
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode($audit->toArray()));
    }

    public function test_a_blank_secret_keeps_the_saved_one(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.integrations.update', 'example'), ['site_key' => 'a', 'secret_key' => self::SECRET]);
        $this->actingAs($this->admin)->put(route('admin.settings.integrations.update', 'example'), ['site_key' => 'b', 'secret_key' => '']);

        $this->assertSame(self::SECRET, app(IntegrationRegistry::class)->value('example', 'secret_key'));
        $this->assertSame('b', app(IntegrationRegistry::class)->value('example', 'site_key'));
    }

    public function test_a_key_set_in_env_is_shown_as_set_via_env_and_not_saved(): void
    {
        config(['app-settings.env.EXAMPLE_SECRET' => 'env-secret-value-1234']);

        $this->actingAs($this->admin)->put(route('admin.settings.integrations.update', 'example'), ['site_key' => 'site', 'secret_key' => self::SECRET]);

        $this->assertSame('env-secret-value-1234', app(IntegrationRegistry::class)->value('example', 'secret_key'));
        $this->actingAs($this->admin)->get(route('admin.settings.show', 'integrations'))
            ->assertSee('data-integration-status="env"', false)
            ->assertSee(__('admin.settings.integrations.secret_env'))
            ->assertDontSee('env-secret-value-1234');
    }

    public function test_the_test_result_is_shown_and_audited(): void
    {
        $this->testResult = 'the service refused the key';

        $this->actingAs($this->admin)
            ->post(route('admin.settings.integrations.test', 'example'))
            ->assertRedirect(route('admin.settings.show', 'integrations'))
            ->assertSessionHasErrors('integration');

        $this->actingAs($this->admin)->get(route('admin.settings.show', 'integrations'))
            ->assertSee('data-integration-last-test', false)
            ->assertSee('the service refused the key');

        $this->testResult = null;
        $this->actingAs($this->admin)
            ->post(route('admin.settings.integrations.test', 'example'))
            ->assertSessionHas('status');

        $this->assertSame(
            ['failed', 'ok'],
            AuditLog::query()->where('event', 'admin.settings.integration_tested')->orderBy('id')->get()->map(fn (AuditLog $log) => $log->context['result'])->all(),
        );
    }

    public function test_an_unknown_integration_is_not_found(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.integrations.update', 'nope'), [])->assertNotFound();
        $this->actingAs($this->admin)->post(route('admin.settings.integrations.test', 'nope'))->assertNotFound();
    }
}
