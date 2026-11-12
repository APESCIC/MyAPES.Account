<?php

namespace App\Core\BotProtection;

use App\Core\Accounts\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Bot checks for the public forms (#637): a hidden honeypot field, a minimum
 * fill time and, once the keys are set in Integrations, Google reCAPTCHA v3.
 * Login asks for reCAPTCHA only after repeated failures. When Google cannot
 * be reached the request is allowed as unverified, logged and held to a
 * stricter throttle; the honeypot and timing checks still apply.
 */
final class BotProtection
{
    public const REGISTER = 'register';

    public const FORGOT_PASSWORD = 'forgot_password';

    public const TICKET = 'ticket';

    public const RECRUITMENT = 'recruitment';

    public const LOGIN = 'login';

    public const FORMS = [self::REGISTER, self::FORGOT_PASSWORD, self::TICKET, self::RECRUITMENT, self::LOGIN];

    public const HONEYPOT_FIELD = 'website_url';

    public const STARTED_FIELD = '_form_started';

    public const TOKEN_FIELD = 'recaptcha_token';

    public const ERROR_KEY = 'bot_protection';

    public function __construct(private readonly RecaptchaVerifier $recaptcha) {}

    public function enabled(): bool
    {
        return (bool) config('myapes.bot_protection.enabled', true);
    }

    /**
     * Guests and public accounts only: staff sign in through APES Cloudron.
     */
    public function appliesTo(Request $request): bool
    {
        $user = $request->user();

        return $this->enabled() && ($user === null || $user->identity_type === User::IDENTITY_LOCAL);
    }

    public function recaptchaActive(string $form, Request $request): bool
    {
        if (! $this->appliesTo($request) || ! $this->recaptcha->configured()) {
            return false;
        }

        return $form !== self::LOGIN || $this->loginNeedsRecaptcha($request);
    }

    public function siteKey(): ?string
    {
        return $this->recaptcha->siteKey();
    }

    public function startedToken(): string
    {
        return Crypt::encryptString((string) now()->getTimestamp());
    }

    /**
     * @throws ValidationException when the submission looks automated
     */
    public function check(Request $request, string $form): void
    {
        if (! $this->appliesTo($request)) {
            return;
        }

        if (trim((string) $request->input(self::HONEYPOT_FIELD, '')) !== '') {
            $this->reject($form, 'honeypot', __('public.bot_protection.rejected'));
        }

        if (! $this->filledSlowlyEnough($request)) {
            $this->reject($form, 'too_fast', __('public.bot_protection.too_fast'));
        }

        if (! $this->recaptchaActive($form, $request)) {
            return;
        }

        $result = $this->recaptcha->verify($request->input(self::TOKEN_FIELD), $form, $request);

        if ($result->status === RecaptchaResult::REJECTED) {
            $this->reject($form, $result->reason, $result->reason === 'low_score'
                ? __('public.bot_protection.low_score')
                : __('public.bot_protection.rejected'));
        }

        if ($result->status === RecaptchaResult::UNVERIFIED) {
            $this->allowUnverified($request, $form, $result->reason);
        }
    }

    public function recordLoginFailure(Request $request): void
    {
        RateLimiter::hit($this->loginFailureKey($request), (int) config('myapes.bot_protection.login_failure_window_seconds', 3600));
    }

    public function clearLoginFailures(Request $request): void
    {
        RateLimiter::clear($this->loginFailureKey($request));
    }

    public function loginNeedsRecaptcha(Request $request): bool
    {
        return RateLimiter::attempts($this->loginFailureKey($request)) >= (int) config('myapes.bot_protection.login_failures_before_recaptcha', 3);
    }

    private function filledSlowlyEnough(Request $request): bool
    {
        try {
            $started = (int) Crypt::decryptString((string) $request->input(self::STARTED_FIELD, ''));
        } catch (DecryptException) {
            return false;
        }

        return $started > 0 && now()->getTimestamp() - $started >= (int) config('myapes.bot_protection.min_fill_seconds', 2);
    }

    private function allowUnverified(Request $request, string $form, string $reason): void
    {
        $key = 'bot-protection-unverified|'.$form.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, (int) config('myapes.bot_protection.unverified_attempts', 5))) {
            $this->reject($form, 'unverified_throttled', __('public.bot_protection.throttled'));
        }
        RateLimiter::hit($key, (int) config('myapes.bot_protection.unverified_window_seconds', 600));

        Log::warning('reCAPTCHA could not verify a public form, so it was allowed as unverified.', [
            'form' => $form,
            'reason' => $reason,
        ]);
    }

    private function loginFailureKey(Request $request): string
    {
        return 'bot-protection-login-failures|'.$request->ip();
    }

    private function reject(string $form, string $reason, string $message): never
    {
        Log::info('Bot protection rejected a public form.', ['form' => $form, 'reason' => $reason]);

        throw ValidationException::withMessages([self::ERROR_KEY => $message]);
    }
}
