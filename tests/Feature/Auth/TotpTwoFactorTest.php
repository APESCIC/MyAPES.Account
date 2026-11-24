<?php

namespace Tests\Feature\Auth;

use App\Core\Accounts\User;
use App\Notifications\Auth\TwoFactorDisabledNotification;
use App\Notifications\Auth\TwoFactorEnabledNotification;
use App\Services\TotpTwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TotpTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_profile_offers_two_factor_setup(): void
    {
        $user = User::factory()->create([
            'password' => 'password',
        ]);

        $this->actingAs($user)
            ->get(route('account.security'))
            ->assertOk()
            ->assertSeeText(__('auth.two_factor.heading'))
            ->assertSee('id="two-factor"', false)
            ->assertSeeText(__('auth.two_factor.enable'));
    }

    public function test_enrol_confirm_enables_two_factor_and_sends_email(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'password' => 'password',
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->from(route('account.security'))
            ->post(route('two-factor.enable'))
            ->assertRedirect(route('two-factor.setup'));

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNotEmpty($user->two_factor_recovery_codes);

        $code = app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);

        $this->actingAs($user)
            ->from(route('two-factor.setup'))
            ->post(route('two-factor.confirm'), ['code' => $code])
            ->assertRedirect(route('account.security').'#two-factor')
            ->assertSessionHas('status', __('flash.two_factor_enabled'));

        $user->refresh();
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertTrue($user->hasEnabledTwoFactor());
        Notification::assertSentTo($user, TwoFactorEnabledNotification::class);

        $this->actingAs($user)
            ->get(route('account.security'))
            ->assertOk()
            ->assertSeeText(__('auth.two_factor.enabled_status'))
            ->assertDontSee('otpauth://', false);
    }

    public function test_login_requires_totp_challenge_after_password(): void
    {
        $user = $this->enrolledUser();
        $code = app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);

        $this->from(route('public.login'))
            ->post(route('public.login.submit'), [
                'login' => $user->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        $this->assertEquals($user->id, session(TotpTwoFactorService::SESSION_LOGIN_ID));

        $this->get(route('two-factor.login'))
            ->assertOk()
            ->assertSeeText(__('auth.two_factor.challenge_heading'));

        $this->post(route('two-factor.login.store'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_recovery_code_works_once_then_is_invalidated(): void
    {
        $service = app(TotpTwoFactorService::class);
        $user = User::factory()->create(['password' => 'password']);
        $enrolment = $service->beginEnrolment($user);
        $plainCode = $enrolment['recovery_codes'][0];
        $otp = app(Google2FA::class)->getCurrentOtp($enrolment['secret']);
        $service->confirmEnrolment($user->refresh(), $otp);

        $this->from(route('public.login'))
            ->post(route('public.login.submit'), [
                'login' => $user->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('two-factor.login'));

        $this->post(route('two-factor.login.store'), ['recovery_code' => $plainCode])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        Auth::logout();
        $this->assertGuest();

        $this->from(route('public.login'))
            ->post(route('public.login.submit'), [
                'login' => $user->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('two-factor.login'));

        $this->from(route('two-factor.login'))
            ->post(route('two-factor.login.store'), ['recovery_code' => $plainCode])
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_disable_requires_step_up_and_sends_email(): void
    {
        Notification::fake();

        $user = $this->enrolledUser();

        $this->actingAs($user)
            ->from(route('account.security'))
            ->delete(route('two-factor.disable'))
            ->assertRedirect(route('password.confirm'));

        $this->assertTrue($user->refresh()->hasEnabledTwoFactor());
        Notification::assertNotSentTo($user, TwoFactorDisabledNotification::class);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->from(route('account.security'))
            ->delete(route('two-factor.disable'))
            ->assertRedirect(route('account.security').'#two-factor')
            ->assertSessionHas('status', __('flash.two_factor_disabled'));

        $this->assertFalse($user->refresh()->hasEnabledTwoFactor());
        $this->assertNull($user->two_factor_secret);
        Notification::assertSentTo($user, TwoFactorDisabledNotification::class);
    }

    public function test_regenerate_recovery_codes_requires_step_up(): void
    {
        $user = $this->enrolledUser();
        $before = $user->two_factor_recovery_codes;

        $this->actingAs($user)
            ->from(route('account.security'))
            ->post(route('two-factor.recovery.regenerate'))
            ->assertRedirect(route('password.confirm'));

        $this->assertSame($before, $user->refresh()->two_factor_recovery_codes);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->from(route('account.security'))
            ->post(route('two-factor.recovery.regenerate'))
            ->assertRedirect(route('account.security').'#two-factor')
            ->assertSessionHas('status', __('flash.two_factor_recovery_regenerated'))
            ->assertSessionHas(TotpTwoFactorService::SESSION_PLAIN_RECOVERY_CODES);

        $this->assertNotSame($before, $user->refresh()->two_factor_recovery_codes);
    }

    public function test_cloudron_oidc_user_cannot_enrol_two_factor(): void
    {
        $user = User::factory()
            ->cloudronIdentity('oidc-totp-denied')
            ->create();

        $this->actingAs($user)
            ->post(route('two-factor.enable'))
            ->assertRedirect(route('staff.auth.step-up'));
    }

    public function test_invalid_totp_does_not_complete_login(): void
    {
        $user = $this->enrolledUser();

        $this->post(route('public.login.submit'), [
            'login' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

        $this->from(route('two-factor.login'))
            ->post(route('two-factor.login.store'), ['code' => '000000'])
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    private function enrolledUser(): User
    {
        $service = app(TotpTwoFactorService::class);
        $user = User::factory()->create(['password' => 'password']);
        $enrolment = $service->beginEnrolment($user);
        $otp = app(Google2FA::class)->getCurrentOtp($enrolment['secret']);
        $service->confirmEnrolment($user->refresh(), $otp);

        return $user->refresh();
    }
}