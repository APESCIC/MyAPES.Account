<?php

namespace Tests\Feature\Auth;

use App\Core\Accounts\User;
use App\Notifications\Auth\PasskeyAddedNotification;
use App\Notifications\Auth\PasskeyRemovedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Passkeys\Events\PasskeyRegistered;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\TestCase;

class PasskeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_options_endpoint_returns_webauthn_challenge_for_guests(): void
    {
        $response = $this->getJson(route('passkey.login-options'));

        $response->assertOk()
            ->assertJsonStructure([
                'options' => [
                    'challenge',
                    'rpId',
                    'timeout',
                ],
            ]);

        $this->assertNotEmpty($response->json('options.challenge'));
        $this->assertSame(
            parse_url((string) config('app.url'), PHP_URL_HOST),
            $response->json('options.rpId'),
        );
        $this->assertTrue(session()->has('passkey.verification_options'));
    }

    public function test_login_verify_rejects_invalid_credential_payload(): void
    {
        $this->getJson(route('passkey.login-options'))->assertOk();

        $this->postJson(route('passkey.login'), [
            'credential' => [
                'id' => 'not-a-real-credential',
                'rawId' => 'not-a-real-credential',
                'type' => 'public-key',
                'response' => [
                    'clientDataJSON' => 'e30',
                    'authenticatorData' => 'e30',
                    'signature' => 'e30',
                ],
            ],
            'remember' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['credential']);

        $this->assertGuest();
    }

    public function test_registration_options_require_auth_step_up_and_local_identity(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->actingAs($user)
            ->getJson(route('passkey.registration-options'))
            ->assertStatus(423);

        $response = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->getJson(route('passkey.registration-options'));

        $response->assertOk()
            ->assertJsonStructure([
                'options' => [
                    'challenge',
                    'rp' => ['id', 'name'],
                    'user' => ['id', 'name', 'displayName'],
                    'pubKeyCredParams',
                ],
            ]);

        $this->assertTrue(session()->has('passkey.registration_options'));
        $this->assertSame(
            parse_url((string) config('app.url'), PHP_URL_HOST),
            $response->json('options.rp.id'),
        );
    }

    public function test_cloudron_oidc_user_cannot_open_registration_options(): void
    {
        $user = User::factory()
            ->cloudronIdentity('oidc-passkey-denied')
            ->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->getJson(route('passkey.registration-options'))
            ->assertForbidden();
    }

    public function test_profile_lists_passkeys_for_local_users(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->passkeys()->create([
            'name' => 'Laptop',
            'credential_id' => Base64UrlSafe::encodeUnpadded('cred-laptop-1'),
            'credential' => ['type' => 'public-key'],
        ]);

        $this->actingAs($user)
            ->get(route('account.security'))
            ->assertOk()
            ->assertSeeText(__('auth.passkeys.heading'))
            ->assertSeeText('Laptop')
            ->assertSee('id="passkeys"', false)
            ->assertSee('data-passkey-register', false);
    }

    public function test_public_login_offers_passkey_button_and_password_fallback(): void
    {
        $this->get(route('public.login'))
            ->assertOk()
            ->assertSeeText(__('auth.passkeys.login_button'))
            ->assertSeeText(__('auth.passkeys.login_fallback'))
            ->assertSee('data-passkey-verify', false)
            ->assertSee('autocomplete="username webauthn"', false);
    }

    public function test_confirm_password_page_offers_passkey_confirm_when_enrolled(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->passkeys()->create([
            'name' => 'Phone',
            'credential_id' => Base64UrlSafe::encodeUnpadded('cred-phone-1'),
            'credential' => ['type' => 'public-key'],
        ]);

        $this->actingAs($user)
            ->get(route('password.confirm'))
            ->assertOk()
            ->assertSeeText(__('auth.passkeys.confirm_button'))
            ->assertSee('data-passkey-confirm', false);
    }

    public function test_passkey_confirm_options_require_authentication(): void
    {
        $this->getJson(route('passkey.confirm-options'))
            ->assertUnauthorized();
    }

    public function test_passkey_confirm_options_return_challenge_for_authenticated_user(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->passkeys()->create([
            'name' => 'Key',
            'credential_id' => Base64UrlSafe::encodeUnpadded('cred-key-1'),
            'credential' => ['type' => 'public-key'],
        ]);

        $this->actingAs($user)
            ->getJson(route('passkey.confirm-options'))
            ->assertOk()
            ->assertJsonStructure([
                'options' => [
                    'challenge',
                    'rpId',
                    'allowCredentials',
                ],
            ]);

        $this->assertTrue(session()->has('passkey.verification_options'));
    }

    public function test_delete_passkey_requires_step_up_then_notifies(): void
    {
        Notification::fake();

        $user = User::factory()->create(['password' => 'password']);
        $passkey = $user->passkeys()->create([
            'name' => 'To remove',
            'credential_id' => Base64UrlSafe::encodeUnpadded('cred-remove-1'),
            'credential' => ['type' => 'public-key'],
        ]);

        $this->actingAs($user)
            ->from(route('account.security'))
            ->delete(route('passkey.destroy', $passkey))
            ->assertRedirect(route('password.confirm'));

        $this->assertDatabaseHas('passkeys', ['id' => $passkey->id]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->from(route('account.security'))
            ->delete(route('passkey.destroy', $passkey))
            ->assertRedirect();

        $this->assertDatabaseMissing('passkeys', ['id' => $passkey->id]);
        Notification::assertSentTo($user, PasskeyRemovedNotification::class);
    }

    public function test_passkey_registered_event_sends_security_email(): void
    {
        Notification::fake();

        $user = User::factory()->create(['password' => 'password']);
        $passkey = $user->passkeys()->make([
            'name' => 'New key',
            'credential_id' => Base64UrlSafe::encodeUnpadded('cred-new-1'),
            'credential' => ['type' => 'public-key'],
        ]);
        $passkey->id = 99;

        Event::dispatch(new PasskeyRegistered($user, $passkey));

        Notification::assertSentTo($user, PasskeyAddedNotification::class);
    }

    public function test_config_relying_party_derived_from_app_url(): void
    {
        $this->assertSame(
            parse_url((string) config('app.url'), PHP_URL_HOST),
            config('passkeys.relying_party_id'),
        );
        $this->assertContains(
            rtrim((string) config('app.url'), '/'),
            config('passkeys.allowed_origins'),
        );
    }
}
