<?php

namespace Tests\Feature\AccountSecurity;

use App\Core\Accounts\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Regression matrix: Cloudron OIDC / directory staff never gain local password reset (#99 / #121 / #234).
 */
class OidcNoLocalResetMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_STATUS_KEY = 'passwords.request_status';

    public function test_cloudron_oidc_staff_cannot_request_or_complete_local_password_reset(): void
    {
        Notification::fake();

        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->cloudronIdentity('matrix-oidc-staff')
            ->create([
                'email' => 'matrix.oidc.staff@example.com',
                'password' => 'password',
            ]);

        $this->assertFalse($staff->isLocalPasswordIdentity());

        $this->from(route('password.request'))
            ->post(route('password.email'), [
                'email' => $staff->email,
            ])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', __(self::GENERIC_STATUS_KEY));

        Notification::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => $staff->email,
        ]);
        $this->assertTrue(Hash::check('password', $staff->refresh()->password));
    }

    public function test_directory_hybrid_and_pending_first_login_cannot_use_guest_reset(): void
    {
        Notification::fake();

        $directory = User::factory()
            ->directoryIdentity('matrix-directory-subject')
            ->create([
                'email' => 'matrix.directory@example.com',
                'password' => 'password',
            ]);
        $hybrid = User::factory()->create([
            'email' => 'matrix.hybrid@example.com',
            'identity_type' => User::IDENTITY_HYBRID,
            'oidc_sub' => 'matrix-hybrid-subject',
            'password' => 'password',
        ]);
        $pending = User::factory()->create([
            'email' => 'matrix.pending@example.com',
            'identity_type' => User::IDENTITY_CLOUDRON_OIDC,
            'oidc_sub' => null,
            'password' => 'password',
        ]);

        foreach ([$directory, $hybrid, $pending] as $refused) {
            $this->assertFalse($refused->isLocalPasswordIdentity());

            $this->from(route('password.request'))
                ->post(route('password.email'), [
                    'email' => $refused->email,
                ])
                ->assertRedirect(route('password.request'))
                ->assertSessionHas('status', __(self::GENERIC_STATUS_KEY));

            $this->assertDatabaseMissing('password_reset_tokens', [
                'email' => $refused->email,
            ]);
            $this->assertTrue(Hash::check('password', $refused->refresh()->password));
        }

        Notification::assertNothingSent();
        Notification::assertNotSentTo($directory, ResetPasswordNotification::class);
    }

    public function test_local_public_still_receives_reset_while_oidc_does_not(): void
    {
        Notification::fake();

        $local = User::factory()->withoutMfa()->create([
            'email' => 'matrix.local.reset@example.com',
            'password' => 'password',
        ]);
        $oidc = User::factory()
            ->cloudronIdentity('matrix-oidc-reset-denied')
            ->create([
                'email' => 'matrix.oidc.reset@example.com',
                'password' => 'password',
            ]);

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $local->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', __(self::GENERIC_STATUS_KEY));

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $oidc->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', __(self::GENERIC_STATUS_KEY));

        Notification::assertSentTo($local, ResetPasswordNotification::class);
        Notification::assertNotSentTo($oidc, ResetPasswordNotification::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $local->email]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $oidc->email]);
    }

    public function test_oidc_public_users_cannot_enrol_totp(): void
    {
        $oidc = User::factory()
            ->cloudronIdentity('matrix-oidc-mfa-denied')
            ->create();

        $this->actingAs($oidc)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->post(route('two-factor.enable'))
            ->assertForbidden();
    }

    public function test_directory_staff_oidc_can_open_passkey_registration_after_step_up(): void
    {
        $staff = User::factory()
            ->protectedRole('staff')
            ->directoryIdentity('matrix-oidc-staff-passkey')
            ->create();

        $this->actingAs($staff)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->getJson(route('passkey.registration-options'))
            ->assertOk()
            ->assertJsonStructure(['options' => ['challenge']]);
    }
}
