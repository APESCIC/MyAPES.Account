<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Api\ApiAbilities;
use App\Core\Api\ApiClient;
use App\Http\Controllers\Admin\AdminApiClientController;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Admin → Settings → API clients (#639).
 */
class ApiClientsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
    }

    public function test_non_administrators_get_403(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)->get(route('admin.settings.show', 'api-clients'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.settings.api-clients.store'), $this->form())->assertForbidden();
        $this->assertSame(0, ApiClient::query()->count());
    }

    public function test_the_token_is_visible_only_once(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.settings.api-clients.store'), $this->form());
        $response->assertRedirect(route('admin.settings.show', 'api-clients'))->assertSessionHas(AdminApiClientController::TOKEN_FLASH_KEY);
        $plain = session(AdminApiClientController::TOKEN_FLASH_KEY)['token'];

        $client = ApiClient::query()->sole();
        $this->assertSame('apes-cic', $client->organisation);
        $this->assertSame([ApiAbilities::STAFF_READ, ApiAbilities::USERS_READ], $client->abilities);
        $token = $client->tokens()->sole();
        $this->assertEqualsWithDelta(now()->addDays(90)->getTimestamp(), $token->expires_at->getTimestamp(), 5);
        $this->assertNotSame($plain, $token->token);
        $this->assertMatchesRegularExpression('/^\d+\|myapes_/', $plain);

        $this->actingAs($this->admin)->get(route('admin.settings.show', 'api-clients'))
            ->assertOk()
            ->assertSee('data-new-api-token', false)
            ->assertSee($plain)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->actingAs($this->admin)->get(route('admin.settings.show', 'api-clients'))
            ->assertOk()
            ->assertDontSee('data-new-api-token', false)
            ->assertDontSee($plain)
            ->assertSee('data-api-client="'.$client->id.'"', false);

        $this->assertStringNotContainsString($plain, (string) json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_the_presets_create_the_approved_clients(): void
    {
        $preset = ApiAbilities::presets()['easm'];
        $this->actingAs($this->admin)->post(route('admin.settings.api-clients.store'), [
            'name' => $preset['name'],
            'organisation' => $preset['organisation'],
            'abilities' => $preset['abilities'],
            'expires_in_days' => 90,
            'rate_limit_per_minute' => 60,
        ])->assertSessionHasNoErrors();

        $client = ApiClient::query()->sole();
        $this->assertSame('shelter-rescue', $client->organisation);
        $this->assertSame([ApiAbilities::USERS_READ, ApiAbilities::PETS_READ, ApiAbilities::TICKETS_READ, ApiAbilities::CASES_READ], $client->abilities);
    }

    public function test_bad_abilities_and_ip_ranges_are_refused(): void
    {
        $this->actingAs($this->admin)->post(route('admin.settings.api-clients.store'), $this->form(['abilities' => ['users:write']]))->assertSessionHasErrors('abilities.0');
        $this->actingAs($this->admin)->post(route('admin.settings.api-clients.store'), $this->form(['ip_allowlist' => "10.0.0.1\nnot-an-ip"]))->assertSessionHasErrors('ip_allowlist');
        $this->actingAs($this->admin)->post(route('admin.settings.api-clients.store'), $this->form(['ip_allowlist' => '10.0.0.0/40']))->assertSessionHasErrors('ip_allowlist');
        $this->assertSame(0, ApiClient::query()->count());
    }

    public function test_a_revoked_or_expired_token_gets_401(): void
    {
        $this->actingAs($this->admin)->post(route('admin.settings.api-clients.store'), $this->form());
        $plain = session(AdminApiClientController::TOKEN_FLASH_KEY)['token'];
        $client = ApiClient::query()->sole();

        $this->resetGuards();
        $this->withToken($plain)->getJson('/api/v1/me')->assertOk();

        $this->travel(91)->days();
        $this->resetGuards();
        $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
        $this->travelBack();

        $this->actingAs($this->admin)->post(route('admin.settings.api-clients.revoke', $client))->assertRedirect();
        $this->assertTrue($client->fresh()->isRevoked());
        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->resetGuards();
        $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_rotation_keeps_the_old_token_for_the_overlap_only(): void
    {
        $this->actingAs($this->admin)->post(route('admin.settings.api-clients.store'), $this->form());
        $old = session(AdminApiClientController::TOKEN_FLASH_KEY)['token'];
        $client = ApiClient::query()->sole();

        $this->actingAs($this->admin)->post(route('admin.settings.api-clients.rotate', $client), ['expires_in_days' => 30])
            ->assertSessionHas(AdminApiClientController::TOKEN_FLASH_KEY);
        $new = session(AdminApiClientController::TOKEN_FLASH_KEY)['token'];
        $this->assertNotSame($old, $new);

        $this->resetGuards();
        $this->withToken($old)->getJson('/api/v1/me')->assertOk();

        $this->travel(25)->hours();
        $this->resetGuards();
        $this->withToken($old)->getJson('/api/v1/me')->assertUnauthorized();
        $this->resetGuards();
        $this->withToken($new)->getJson('/api/v1/me')->assertOk();
    }

    public function test_every_action_is_audit_logged(): void
    {
        $this->actingAs($this->admin)->post(route('admin.settings.api-clients.store'), $this->form());
        $client = ApiClient::query()->sole();
        $this->actingAs($this->admin)->put(route('admin.settings.api-clients.update', $client), ['ip_allowlist' => '203.0.113.10', 'rate_limit_per_minute' => 30]);
        $this->actingAs($this->admin)->post(route('admin.settings.api-clients.rotate', $client), ['expires_in_days' => 90]);
        $this->actingAs($this->admin)->post(route('admin.settings.api-clients.revoke', $client));

        $this->assertSame(
            ['admin.api_clients.created', 'admin.api_clients.updated', 'admin.api_clients.rotated', 'admin.api_clients.revoked'],
            AuditLog::query()->where('event', 'like', 'admin.api_clients.%')->orderBy('id')->pluck('event')->all(),
        );
        $this->assertSame(['203.0.113.10'], $client->fresh()->ip_allowlist);
        $this->assertSame(30, $client->fresh()->rate_limit_per_minute);
        $this->assertSame((int) $this->admin->id, (int) AuditLog::query()->where('event', 'admin.api_clients.revoked')->value('user_id'));
    }

    private function resetGuards(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'name' => 'APES Newsroom',
            'organisation' => 'apes-cic',
            'abilities' => [ApiAbilities::STAFF_READ, ApiAbilities::USERS_READ],
            'expires_in_days' => 90,
            'ip_allowlist' => '',
            'rate_limit_per_minute' => 60,
            ...$overrides,
        ];
    }
}
