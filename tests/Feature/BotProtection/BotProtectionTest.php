<?php

namespace Tests\Feature\BotProtection;

use App\Core\Accounts\User;
use App\Core\BotProtection\BotProtection;
use App\Core\BotProtection\RecaptchaVerifier;
use App\Core\Integrations\CoreIntegrations;
use App\Core\Integrations\IntegrationRegistry;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Google reCAPTCHA v3 and the honeypot on the public forms (#637).
 */
class BotProtectionTest extends TestCase
{
    use RefreshDatabase;

    private const SITE_KEY = 'site-key-for-tests';

    private const SECRET_KEY = 'not-a-real-recaptcha-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'myapes.bot_protection.enabled' => true,
            'app-settings.env.RECAPTCHA_SITE_KEY' => self::SITE_KEY,
            'app-settings.env.RECAPTCHA_SECRET_KEY' => self::SECRET_KEY,
        ]);
    }

    public function test_a_missing_token_is_rejected_without_asking_google(): void
    {
        Http::fake();

        $this->forgotPassword(['recaptcha_token' => ''])->assertSessionHasErrors(BotProtection::ERROR_KEY);
        Http::assertNothingSent();
    }

    public function test_a_bad_token_is_rejected(): void
    {
        Http::fake([RecaptchaVerifier::VERIFY_URL => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

        $this->forgotPassword()->assertSessionHasErrors([BotProtection::ERROR_KEY => __('public.bot_protection.rejected')]);
        Http::assertSent(fn (HttpRequest $request): bool => $request['secret'] === self::SECRET_KEY && $request['response'] === 'browser-token');
    }

    public function test_a_token_for_another_action_or_host_is_rejected(): void
    {
        Http::fake([RecaptchaVerifier::VERIFY_URL => Http::sequence()
            ->push($this->google(action: BotProtection::REGISTER))
            ->push($this->google(hostname: 'evil.example'))]);

        $this->forgotPassword()->assertSessionHasErrors(BotProtection::ERROR_KEY);
        $this->forgotPassword()->assertSessionHasErrors(BotProtection::ERROR_KEY);
    }

    public function test_a_score_below_the_admin_threshold_is_rejected_with_a_clear_message(): void
    {
        app(IntegrationRegistry::class)->save(CoreIntegrations::RECAPTCHA, ['threshold_forgot_password' => '0.7']);
        Http::fake([RecaptchaVerifier::VERIFY_URL => Http::sequence()
            ->push($this->google(score: 0.6))
            ->push($this->google(score: 0.8))]);

        $this->forgotPassword()->assertSessionHasErrors([BotProtection::ERROR_KEY => __('public.bot_protection.low_score')]);
        $this->forgotPassword()->assertSessionHasNoErrors()->assertSessionHas('status');
    }

    public function test_a_filled_honeypot_or_a_too_quick_form_is_rejected(): void
    {
        Http::fake();

        $this->forgotPassword([BotProtection::HONEYPOT_FIELD => 'https://spam.example'])
            ->assertSessionHasErrors([BotProtection::ERROR_KEY => __('public.bot_protection.rejected')]);
        $this->forgotPassword([BotProtection::STARTED_FIELD => Crypt::encryptString((string) now()->getTimestamp())])
            ->assertSessionHasErrors([BotProtection::ERROR_KEY => __('public.bot_protection.too_fast')]);
        $this->forgotPassword([BotProtection::STARTED_FIELD => 'not-a-real-token'])
            ->assertSessionHasErrors(BotProtection::ERROR_KEY);
        Http::assertNothingSent();
    }

    public function test_a_google_timeout_is_allowed_as_unverified_logged_throttled_and_shown_to_admins(): void
    {
        config(['myapes.bot_protection.unverified_attempts' => 2]);
        Http::fake(static fn () => throw new ConnectionException('Operation timed out'));
        Log::spy();

        $this->forgotPassword()->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->forgotPassword()->assertSessionHasNoErrors();
        $this->forgotPassword()->assertSessionHasErrors([BotProtection::ERROR_KEY => __('public.bot_protection.throttled')]);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context === ['form' => BotProtection::FORGOT_PASSWORD, 'reason' => 'unreachable']);

        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $this->actingAs($admin)->get(route('admin.settings.show', 'integrations'))
            ->assertOk()
            ->assertSee('data-integration-warning', false)
            ->assertDontSee(self::SECRET_KEY);
    }

    public function test_the_honeypot_and_timing_still_apply_when_google_cannot_be_reached(): void
    {
        Http::fake(static fn () => throw new ConnectionException('Operation timed out'));

        $this->forgotPassword([BotProtection::HONEYPOT_FIELD => 'filled'])->assertSessionHasErrors(BotProtection::ERROR_KEY);
    }

    public function test_the_script_and_privacy_wording_appear_only_on_protected_forms(): void
    {
        foreach ([route('public.register'), route('password.request')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('https://www.google.com/recaptcha/api.js?render='.self::SITE_KEY, false)
                ->assertSee('data-recaptcha-notice', false)
                ->assertSee('https://policies.google.com/privacy', false)
                ->assertSee('name="'.BotProtection::HONEYPOT_FIELD.'"', false);
        }

        foreach (['/', route('privacy'), route('cookies')] as $url) {
            $this->get($url)->assertOk()->assertDontSee('recaptcha/api.js', false);
        }

        $this->get(route('privacy'))->assertSee('data-privacy-recaptcha', false)->assertSee(__('public.legal.privacy.blade.spam_protection'));
        $this->get(route('cookies'))->assertSee('data-cookies-recaptcha', false);
    }

    public function test_without_keys_only_the_honeypot_and_timing_run(): void
    {
        config(['app-settings.env.RECAPTCHA_SITE_KEY' => null, 'app-settings.env.RECAPTCHA_SECRET_KEY' => null]);
        Http::fake();

        $this->get(route('public.register'))->assertDontSee('recaptcha/api.js', false)->assertSee('name="'.BotProtection::HONEYPOT_FIELD.'"', false);
        $this->forgotPassword(['recaptcha_token' => ''])->assertSessionHasNoErrors();
        Http::assertNothingSent();
    }

    public function test_googles_test_keys_work_locally(): void
    {
        config(['app-settings.env.RECAPTCHA_SITE_KEY' => RecaptchaVerifier::TEST_SITE_KEY]);
        Http::fake([RecaptchaVerifier::VERIFY_URL => Http::response(['success' => true, 'hostname' => 'testkey.google.com'])]);

        $this->forgotPassword()->assertSessionHasNoErrors()->assertSessionHas('status');
    }

    public function test_login_asks_for_recaptcha_only_after_three_failures(): void
    {
        Http::fake([RecaptchaVerifier::VERIFY_URL => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create(['password' => 'correct-horse-battery']);

        $this->assertFalse(app(BotProtection::class)->loginNeedsRecaptcha(request()));

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post(route('public.login.submit'), $this->guarded(['login' => $user->email, 'password' => 'wrong-password']))
                ->assertSessionHasErrors('login')
                ->assertSessionDoesntHaveErrors(BotProtection::ERROR_KEY);
        }
        Http::assertNothingSent();

        $this->post(route('public.login.submit'), $this->guarded(['login' => $user->email, 'password' => 'correct-horse-battery']))
            ->assertSessionHasErrors(BotProtection::ERROR_KEY);
        $this->assertGuest();
    }

    public function test_staff_accounts_are_not_asked(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->directoryIdentity('staff-subject')->create();
        $request = request();
        $request->setUserResolver(static fn () => $staff);

        $this->assertFalse(app(BotProtection::class)->appliesTo($request));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function forgotPassword(array $overrides = []): TestResponse
    {
        return $this->from(route('password.request'))->post(route('password.email'), $this->guarded(['email' => 'nobody@example.test', ...$overrides]));
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function guarded(array $fields): array
    {
        return [
            BotProtection::HONEYPOT_FIELD => '',
            BotProtection::STARTED_FIELD => Crypt::encryptString((string) now()->subSeconds(30)->getTimestamp()),
            BotProtection::TOKEN_FIELD => 'browser-token',
            ...$fields,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function google(float $score = 0.9, string $action = BotProtection::FORGOT_PASSWORD, ?string $hostname = null): array
    {
        $hostname ??= (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return ['success' => true, 'score' => $score, 'action' => $action, 'hostname' => $hostname, 'challenge_ts' => now()->toIso8601String()];
    }
}
