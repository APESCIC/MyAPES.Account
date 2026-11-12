<?php

namespace Tests\Feature\Api;

use App\Core\Accounts\User;
use App\Core\Api\ApiAbilities;
use App\Core\Api\ApiClient;
use App\Core\Api\ApiRoutes;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The /api/v1 base (#638): Sanctum service tokens with abilities, a rate
 * limit per token, an optional IP allowlist and JSON errors.
 */
class ApiFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ApiRoutes::v1(function (): void {
            Route::get('test-pets', static fn () => response()->json(['data' => []]))->middleware('abilities:'.ApiAbilities::PETS_READ)->name('test-pets');
        });
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_no_token_gives_a_json_401(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
        $this->get('/api/v1/me')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        $this->withToken('not-a-real-token')->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_session_is_never_enough(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_valid_token_reads_its_own_client(): void
    {
        [$client, $token] = $this->client([ApiAbilities::USERS_READ]);

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.client', 'APES Newsroom')
            ->assertJsonPath('data.organisation', 'apes-cic')
            ->assertJsonPath('data.abilities', [ApiAbilities::USERS_READ]);

        $this->assertNotNull($client->fresh()->last_used_at);
        $this->assertSame('127.0.0.1', $client->fresh()->last_used_ip);
    }

    public function test_a_wrong_ability_gives_403(): void
    {
        [, $withoutPets] = $this->client([ApiAbilities::USERS_READ]);
        [, $withPets] = $this->client([ApiAbilities::PETS_READ]);

        $this->withToken($withoutPets)->getJson('/api/v1/test-pets')->assertForbidden()->assertJsonStructure(['message']);
        $this->app['auth']->forgetGuards();
        $this->withToken($withPets)->getJson('/api/v1/test-pets')->assertOk();
    }

    public function test_going_over_the_limit_gives_429(): void
    {
        [, $token] = $this->client([ApiAbilities::USERS_READ], rateLimit: 2);

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $this->withToken($token)->getJson('/api/v1/me')->assertTooManyRequests()->assertJsonStructure(['message']);
    }

    public function test_a_blocked_ip_gives_403(): void
    {
        [, $blocked] = $this->client([ApiAbilities::USERS_READ], allowlist: ['10.20.0.0/16']);
        [, $allowed] = $this->client([ApiAbilities::USERS_READ], allowlist: ['10.20.0.0/16', '127.0.0.1']);

        $this->withToken($blocked)->getJson('/api/v1/me')->assertForbidden()->assertJsonStructure(['message']);
        $this->app['auth']->forgetGuards();
        $this->withToken($allowed)->getJson('/api/v1/me')->assertOk();
    }

    public function test_a_revoked_client_or_expired_token_gives_401(): void
    {
        [$client, $token] = $this->client([ApiAbilities::USERS_READ]);
        $client->forceFill(['revoked_at' => now()])->save();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();

        [, $expired] = $this->client([ApiAbilities::USERS_READ], expiresAt: now()->subMinute());
        $this->app['auth']->forgetGuards();
        $this->withToken($expired)->getJson('/api/v1/me')->assertUnauthorized();
    }

    /**
     * @param  list<string>  $abilities
     * @param  list<string>|null  $allowlist
     * @return array{ApiClient, string}
     */
    private function client(array $abilities, int $rateLimit = 60, ?array $allowlist = null, ?DateTimeInterface $expiresAt = null): array
    {
        $client = ApiClient::query()->create([
            'name' => 'APES Newsroom',
            'organisation' => 'apes-cic',
            'abilities' => $abilities,
            'ip_allowlist' => $allowlist,
            'rate_limit_per_minute' => $rateLimit,
        ]);

        return [$client, $client->createToken('test', $abilities, $expiresAt ?? now()->addDays(90))->plainTextToken];
    }
}
