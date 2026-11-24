<?php

namespace Tests\Feature\AccountSecurity;

use App\Core\Accounts\PendingEmailChange;
use App\Core\Accounts\User;
use App\Notifications\Auth\EmailChangeCompletedNotification;
use App\Notifications\Auth\EmailChangeConfirmNotification;
use App\Notifications\Auth\EmailChangeStartedNotification;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Notifications\Auth\TwoFactorDisabledNotification;
use App\Notifications\Auth\TwoFactorEnabledNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Services\TotpTwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Cross-cutting account-security matrix for epic #225 / task #234.
 *
 * Covers the epic checklist end-to-end with shared factories (with/without
 * TOTP and passkeys). Child-specific suites under Tests\Feature\Auth remain
 * the deep coverage; this matrix asserts the line still holds together.
 */
class AccountSecurityMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'Correct-horse-42-matrix!';

    public function test_factory_states_build_local_users_with_and_without_mfa_factors(): void
    {
        $baseline = User::factory()->withoutMfa()->create(['password' => 'password']);
        $totpOnly = User::factory()->withTotp()->create(['password' => 'password']);
        $passkeyOnly = User::factory()->withPasskey('Laptop')->create(['password' => 'password']);
        $both = User::factory()->withTotp()->withPasskey('Phone')->create(['password' => 'password']);

        $this->assertTrue($baseline->isLocalPasswordIdentity());
        $this->assertFalse($baseline->hasEnabledTwoFactor());
        $this->assertSame(0, $baseline->passkeys()->count());

        $this->assertTrue($totpOnly->refresh()->hasEnabledTwoFactor());
        $this->assertSame(0, $totpOnly->passkeys()->count());

        $this->assertFalse($passkeyOnly->hasEnabledTwoFactor());
        $this->assertSame(1, $passkeyOnly->passkeys()->count());
        $this->assertSame('Laptop', $passkeyOnly->passkeys()->first()->name);

        $this->assertTrue($both->refresh()->hasEnabledTwoFactor());
        $this->assertSame(1, $both->passkeys()->count());
        $this->assertSame('Phone', $both->passkeys()->first()->name);
    }

    public function test_username_password_and_verify_gates_harden_local_public_accounts(): void
    {
        Notification::fake();

        $this->from(route('public.register'))
            ->post(route('public.register.submit'), [
                'first_name' => 'Matrix',
                'last_name' => 'Short',
                'date_of_birth' => '14/03/1990',
                'username' => 'admin',
                'email' => 'matrix.short@example.com',
                'password' => 'Short-horse',
                'password_confirmation' => 'Short-horse',
                'services' => ['apes-cic'],
                'registration_consent' => '1',
            ])
            ->assertRedirect(route('public.register'))
            ->assertSessionHasErrors(['username', 'password']);

        $this->stubUncompromisedPasswords(false);

        $this->from(route('public.register'))
            ->post(route('public.register.submit'), [
                'first_name' => 'Matrix',
                'last_name' => 'Breached',
                'date_of_birth' => '14/03/1990',
                'username' => 'matrix.breached',
                'email' => 'matrix.breached@example.com',
                'password' => self::STRONG_PASSWORD,
                'password_confirmation' => self::STRONG_PASSWORD,
                'services' => ['apes-cic'],
                'registration_consent' => '1',
            ])
            ->assertRedirect(route('public.register'))
            ->assertSessionHasErrors('password');

        $this->stubUncompromisedPasswords(true);

        $this->from(route('public.register'))
            ->post(route('public.register.submit'), [
                'first_name' => 'Matrix',
                'last_name' => 'Ok',
                'date_of_birth' => '14/03/1990',
                'username' => 'matrix.ok',
                'email' => 'matrix.ok@example.com',
                'password' => self::STRONG_PASSWORD,
                'password_confirmation' => self::STRONG_PASSWORD,
                'services' => ['apes-cic'],
                'registration_consent' => '1',
            ])
            ->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'matrix.ok@example.com')->sole();
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmailNotification::class);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_step_up_gates_username_email_password_and_totp_mutations(): void
    {
        Notification::fake();

        $user = User::factory()->withoutMfa()->create([
            'username' => 'matrix.stepup',
            'email' => 'matrix.stepup@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($user)
            ->put(route('profile.username.update'), ['username' => 'matrix.renamed'])
            ->assertRedirect(route('password.confirm'));
        $this->assertSame('matrix.stepup', $user->refresh()->username);

        $this->actingAs($user)
            ->post(route('profile.email.change'), ['email' => 'matrix.new@example.com'])
            ->assertRedirect(route('password.confirm'));
        $this->assertSame('matrix.stepup@example.com', $user->refresh()->email);

        $this->actingAs($user)
            ->put(route('profile.password.update'), [
                'current_password' => 'password',
                'password' => self::STRONG_PASSWORD,
                'password_confirmation' => self::STRONG_PASSWORD,
            ])
            ->assertRedirect(route('password.confirm'));
        $this->assertTrue(Hash::check('password', $user->refresh()->password));

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->put(route('profile.username.update'), ['username' => 'matrix.renamed'])
            ->assertRedirect(route('account.show', [], false).'#username')
            ->assertSessionHas('status', __('flash.username_updated'));
        $this->assertSame('matrix.renamed', $user->refresh()->username);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->post(route('profile.email.change'), ['email' => 'matrix.new@example.com'])
            ->assertRedirect(route('account.show', [], false).'#account-email')
            ->assertSessionHas('status', __('flash.email_change_started'));

        Notification::assertSentTo($user, EmailChangeStartedNotification::class);
        Notification::assertSentOnDemand(EmailChangeConfirmNotification::class);

        $pending = PendingEmailChange::query()->where('user_id', $user->id)->firstOrFail();
        $url = URL::temporarySignedRoute(
            'email.change.confirm',
            $pending->expires_at,
            ['user' => $user->id, 'hash' => $pending->emailHash()],
        );

        $this->actingAs($user)
            ->get($url)
            ->assertRedirect(route('account.show', [], false).'#account-email')
            ->assertSessionHas('status', __('flash.email_change_completed'));

        $this->assertSame('matrix.new@example.com', $user->refresh()->email);
        Notification::assertSentTo($user, EmailChangeCompletedNotification::class);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->put(route('profile.password.update'), [
                'current_password' => 'password',
                'password' => self::STRONG_PASSWORD,
                'password_confirmation' => self::STRONG_PASSWORD,
            ])
            ->assertRedirect(route('account.security').'#password')
            ->assertSessionHas('status', __('flash.password_updated'));

        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $user->refresh()->password));
        Notification::assertSentTo($user, PasswordChangedNotification::class);
    }

    public function test_totp_enrol_challenge_and_disable_with_factory_and_step_up(): void
    {
        Notification::fake();

        $user = User::factory()->withoutMfa()->create([
            'email' => 'matrix.totp@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->post(route('two-factor.enable'))
            ->assertRedirect(route('two-factor.setup'));

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);

        $code = app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);

        $this->actingAs($user)
            ->post(route('two-factor.confirm'), ['code' => $code])
            ->assertRedirect(route('account.security').'#two-factor')
            ->assertSessionHas('status', __('flash.two_factor_enabled'));

        $this->assertTrue($user->refresh()->hasEnabledTwoFactor());
        Notification::assertSentTo($user, TwoFactorEnabledNotification::class);

        Auth::logout();
        $this->assertGuest();

        $enrolled = User::factory()->withTotp()->create([
            'email' => 'matrix.totp.login@example.com',
            'password' => 'password',
        ]);
        $otp = app(Google2FA::class)->getCurrentOtp($enrolled->two_factor_secret);

        $this->from(route('public.login'))
            ->post(route('public.login.submit'), [
                'login' => $enrolled->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('two-factor.login'));

        $this->post(route('two-factor.login.store'), ['code' => $otp])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($enrolled);

        $timeout = (int) config('auth.password_timeout');
        $this->actingAs($enrolled)
            ->withSession(['auth.password_confirmed_at' => now()->unix() - $timeout - 1])
            ->delete(route('two-factor.disable'))
            ->assertRedirect(route('password.confirm'));
        $this->assertTrue($enrolled->refresh()->hasEnabledTwoFactor());

        $this->actingAs($enrolled)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->delete(route('two-factor.disable'))
            ->assertRedirect(route('account.security').'#two-factor');

        $this->assertFalse($enrolled->refresh()->hasEnabledTwoFactor());
        Notification::assertSentTo($enrolled, TwoFactorDisabledNotification::class);
    }

    public function test_passkey_management_requires_step_up_and_lists_factory_credentials(): void
    {
        $user = User::factory()->withPasskey('Matrix Key')->create([
            'password' => 'password',
        ]);
        $passkey = $user->passkeys()->firstOrFail();

        $this->actingAs($user)
            ->get(route('account.security'))
            ->assertOk()
            ->assertSeeText(__('auth.passkeys.heading'))
            ->assertSeeText('Matrix Key');

        $this->actingAs($user)
            ->getJson(route('passkey.registration-options'))
            ->assertStatus(423);

        $this->actingAs($user)
            ->from(route('account.security'))
            ->delete(route('passkey.destroy', $passkey))
            ->assertRedirect(route('password.confirm'));
        $this->assertDatabaseHas('passkeys', ['id' => $passkey->id]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->getJson(route('passkey.registration-options'))
            ->assertOk()
            ->assertJsonPath(
                'options.rp.id',
                parse_url((string) config('app.url'), PHP_URL_HOST),
            );

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->from(route('account.security'))
            ->delete(route('passkey.destroy', $passkey))
            ->assertRedirect();
        $this->assertDatabaseMissing('passkeys', ['id' => $passkey->id]);
    }

    public function test_staff_login_does_not_offer_public_forgot_password(): void
    {
        $this->get(route('public.login'))
            ->assertOk()
            ->assertSee('href="'.route('password.request').'"', false)
            ->assertSeeText(__('auth.passkeys.login_button'));

        $this->get(route('staff.login'))
            ->assertOk()
            ->assertDontSee(route('password.request'), false)
            ->assertDontSeeText('Forgot password');
    }

    public function test_recovery_path_documented_via_plain_codes_session_after_regenerate(): void
    {
        $user = User::factory()->withTotp()->create(['password' => 'password']);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->post(route('two-factor.recovery.regenerate'))
            ->assertRedirect(route('account.security').'#two-factor')
            ->assertSessionHas(TotpTwoFactorService::SESSION_PLAIN_RECOVERY_CODES);

        $codes = session(TotpTwoFactorService::SESSION_PLAIN_RECOVERY_CODES);
        $this->assertIsArray($codes);
        $this->assertCount(TotpTwoFactorService::RECOVERY_CODE_COUNT, $codes);
    }
}
