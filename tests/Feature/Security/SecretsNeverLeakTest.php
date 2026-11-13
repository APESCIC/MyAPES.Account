<?php

namespace Tests\Feature\Security;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Api\ApiAbilities;
use App\Core\Api\ApiClient;
use App\Core\Integrations\CoreIntegrations;
use App\Core\Integrations\IntegrationRegistry;
use App\Core\Webhooks\WebhookEndpoint;
use App\Core\Webhooks\WebhookEvents;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * v0.62 security rules together (#645): no secret saved in Integrations, no
 * API token and no webhook signing secret appears in any page, API response,
 * health check, audit row or database column in plain text.
 */
class SecretsNeverLeakTest extends TestCase
{
    use RefreshDatabase;

    private const RECAPTCHA_SECRET = 'not-a-real-recaptcha-secret-0042';

    private const WEBHOOK_SECRET = 'whsec_not-a-real-signing-secret-0042';

    public function test_no_secret_appears_in_any_response(): void
    {
        config(['app-settings.env.RECAPTCHA_SECRET_KEY' => null, 'app-settings.env.RECAPTCHA_SITE_KEY' => null, 'myapes.bot_protection.enabled' => true]);
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($admin)->put(route('admin.settings.integrations.update', CoreIntegrations::RECAPTCHA), [
            'site_key' => 'public-site-key-0042',
            'secret_key' => self::RECAPTCHA_SECRET,
        ])->assertSessionHasNoErrors();
        $client = ApiClient::query()->create(['name' => 'EASM', 'organisation' => 'shelter-rescue', 'abilities' => ApiAbilities::all()]);
        $token = $client->createToken('test', ApiAbilities::all(), now()->addDay())->plainTextToken;
        WebhookEndpoint::query()->create(['name' => 'EASM', 'url' => 'https://easm.example.org/hook', 'organisation' => 'shelter-rescue', 'events' => WebhookEvents::all(), 'secret' => self::WEBHOOK_SECRET, 'active' => true]);

        $this->assertSame(self::RECAPTCHA_SECRET, app(IntegrationRegistry::class)->value(CoreIntegrations::RECAPTCHA, 'secret_key'));
        $secrets = [self::RECAPTCHA_SECRET, self::WEBHOOK_SECRET, explode('|', $token, 2)[1]];

        $bodies = [];
        foreach (['integrations', 'api-clients', 'webhooks', 'api-docs'] as $tab) {
            $bodies[$tab] = (string) $this->actingAs($admin)->get(route('admin.settings.show', $tab))->assertOk()->getContent();
        }
        $bodies['email-import'] = (string) $this->actingAs($admin)->get(route('admin.emails.import.index'))->assertOk()->getContent();
        auth()->logout();
        foreach (['public.register', 'password.request', 'privacy', 'cookies', 'health'] as $route) {
            $bodies[$route] = (string) $this->get(route($route))->getContent();
        }
        $this->app['auth']->forgetGuards();
        $bodies['api'] = (string) $this->withToken($token)->getJson('/api/v1/me')->assertOk()->getContent();
        $bodies['audit'] = (string) json_encode(AuditLog::query()->get()->toArray());
        $bodies['database'] = (string) json_encode([
            DB::table('app_settings')->get(),
            DB::table('webhook_endpoints')->get(),
            DB::table('personal_access_tokens')->get(),
        ]);

        foreach ($bodies as $where => $body) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $body, 'A secret appeared in '.$where);
            }
        }
        $this->assertStringContainsString('recaptcha/api.js?render=public-site-key-0042', $bodies['public.register']);
    }
}
