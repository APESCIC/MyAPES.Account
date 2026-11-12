<?php

namespace App\Core\BotProtection;

use App\Core\Integrations\CoreIntegrations;
use App\Core\Integrations\IntegrationRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Checks a Google reCAPTCHA v3 token server side (#637): the token, its
 * action, the hostname and the score against the admin threshold for the form.
 */
final class RecaptchaVerifier
{
    public const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /** Google's published test site key, which always passes without a score or action. */
    public const TEST_SITE_KEY = '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI';

    /** Sent by the browser when Google's script could not load, so the fallback applies. */
    public const UNAVAILABLE_TOKEN = 'unavailable';

    private const UNVERIFIED_CACHE_KEY = 'recaptcha:last-unverified';

    private const WARNING_HOURS = 24;

    private const SECRET_ERRORS = ['missing-input-secret', 'invalid-input-secret'];

    public function __construct(private readonly IntegrationRegistry $registry) {}

    public function configured(): bool
    {
        return $this->registry->configured(CoreIntegrations::RECAPTCHA);
    }

    public function siteKey(): ?string
    {
        $key = $this->registry->value(CoreIntegrations::RECAPTCHA, 'site_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function threshold(string $form): float
    {
        $value = $this->registry->value(CoreIntegrations::RECAPTCHA, 'threshold_'.$form);

        return is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : 0.5;
    }

    public function verify(?string $token, string $action, Request $request): RecaptchaResult
    {
        if ($token === null || trim($token) === '') {
            return RecaptchaResult::rejected('missing_token');
        }
        if ($token === self::UNAVAILABLE_TOKEN) {
            return $this->unverified('script_unavailable');
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('myapes.bot_protection.google_timeout_seconds', 3))
                ->post(self::VERIFY_URL, [
                    'secret' => (string) $this->registry->value(CoreIntegrations::RECAPTCHA, 'secret_key'),
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);
        } catch (ConnectionException|RequestException) {
            return $this->unverified('unreachable');
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return $this->unverified('http_'.$response->status());
        }

        $body = $response->json();
        if (($body['success'] ?? false) !== true) {
            $errors = array_map('strval', (array) ($body['error-codes'] ?? []));

            return array_intersect($errors, self::SECRET_ERRORS) !== []
                ? $this->unverified('secret_rejected')
                : RecaptchaResult::rejected('invalid_token');
        }

        if ($this->siteKey() === self::TEST_SITE_KEY) {
            return RecaptchaResult::passed(null);
        }

        if (($body['action'] ?? null) !== $action) {
            return RecaptchaResult::rejected('wrong_action');
        }

        if (! in_array(strtolower((string) ($body['hostname'] ?? '')), $this->allowedHostnames($request), true)) {
            return RecaptchaResult::rejected('wrong_hostname');
        }

        $score = is_numeric($body['score'] ?? null) ? (float) $body['score'] : 0.0;

        return $score < $this->threshold($action)
            ? RecaptchaResult::rejected('low_score', $score)
            : RecaptchaResult::passed($score);
    }

    /**
     * The Integrations connection test: Google answers a dummy token with
     * "invalid-input-response" when the secret key is right.
     */
    public function testSecret(): ?string
    {
        if (! $this->configured()) {
            return __('admin.settings.integrations.recaptcha.test_not_configured');
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('myapes.bot_protection.google_timeout_seconds', 3))
                ->post(self::VERIFY_URL, [
                    'secret' => (string) $this->registry->value(CoreIntegrations::RECAPTCHA, 'secret_key'),
                    'response' => 'myapes-connection-test',
                ]);
        } catch (ConnectionException|RequestException) {
            return __('admin.settings.integrations.recaptcha.test_unreachable');
        }

        $errors = array_map('strval', (array) ($response->json('error-codes') ?? []));

        return match (true) {
            ! $response->successful() => __('admin.settings.integrations.recaptcha.test_unreachable'),
            array_intersect($errors, self::SECRET_ERRORS) !== [] => __('admin.settings.integrations.recaptcha.test_bad_secret'),
            default => null,
        };
    }

    public function lastUnverifiedAt(): ?Carbon
    {
        $at = Cache::get(self::UNVERIFIED_CACHE_KEY);

        return is_string($at) ? Carbon::parse($at) : null;
    }

    /**
     * Shown on Admin → Settings → Integrations when Google could not be asked in the last day.
     */
    public function warning(): ?string
    {
        $at = $this->lastUnverifiedAt();
        if ($at === null || $at->lt(now()->subHours(self::WARNING_HOURS))) {
            return null;
        }

        return __('admin.settings.integrations.recaptcha.unverified_warning', ['at' => $at->format('Y-m-d H:i')]);
    }

    private function unverified(string $reason): RecaptchaResult
    {
        Cache::put(self::UNVERIFIED_CACHE_KEY, now()->toIso8601String(), now()->addHours(self::WARNING_HOURS));

        return RecaptchaResult::unverified($reason);
    }

    /**
     * @return list<string>
     */
    private function allowedHostnames(Request $request): array
    {
        return array_values(array_unique(array_filter([
            strtolower($request->getHost()),
            strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
        ])));
    }
}
