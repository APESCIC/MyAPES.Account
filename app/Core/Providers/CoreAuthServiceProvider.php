<?php

namespace App\Core\Providers;

use App\Contracts\OidcIdentityProvider;
use App\Core\Accounts\User;
use App\Http\Responses\Passkeys\PasskeyConfirmationResponse;
use App\Http\Responses\Passkeys\PasskeyLoginResponse;
use App\Listeners\SendPasskeyDeletedNotification;
use App\Listeners\SendPasskeyRegisteredNotification;
use App\Services\AuthorizationProfile;
use App\Services\JumbojettOidcIdentityProvider;
use App\Services\TotpTwoFactorService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Contracts\PasskeyConfirmationResponse as PasskeyConfirmationResponseContract;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

/**
 * Core auth bindings and public-account rate limiters (#282, #230, #232).
 */
class CoreAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OidcIdentityProvider::class, JumbojettOidcIdentityProvider::class);
        $this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);
        $this->app->singleton(PasskeyConfirmationResponseContract::class, PasskeyConfirmationResponse::class);

        Passkeys::useUserModel(User::class);
    }

    public function boot(): void
    {
        Event::listen(PasskeyRegistered::class, SendPasskeyRegisteredNotification::class);
        Event::listen(PasskeyDeleted::class, SendPasskeyDeletedNotification::class);

        Passkeys::authorizeLoginUsing(function (Request $request, $user, Passkey $passkey): bool {
            if (! $user instanceof User) {
                return false;
            }

            if ($user->suspended_at !== null) {
                throw ValidationException::withMessages([
                    'credential' => [__('auth.passkeys.suspended')],
                ]);
            }

            if (! $user->isLocalPasswordIdentity()) {
                throw ValidationException::withMessages([
                    'credential' => [__('auth.passkeys.local_only')],
                ]);
            }

            if (app(AuthorizationProfile::class)->hasDirectoryProtectedEligibility($user)) {
                throw ValidationException::withMessages([
                    'credential' => [__('auth.passkeys.staff_use_oidc')],
                ]);
            }

            return true;
        });

        RateLimiter::for('public-login', function (Request $request): array {
            $login = Str::lower((string) ($request->input('login') ?: $request->input('email')));

            return [
                Limit::perMinute(10)->by(Str::transliterate('ip|'.$request->ip())),
                Limit::perMinute(5)->by(Str::transliterate('account|'.$login)),
            ];
        });

        RateLimiter::for('public-password-reset-request', function (Request $request): array {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by(Str::transliterate('ip|'.$request->ip())),
                Limit::perMinute(3)->by(Str::transliterate('account|'.$email)),
            ];
        });

        RateLimiter::for('public-password-reset-submit', function (Request $request): array {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by(Str::transliterate('ip|'.$request->ip())),
                Limit::perMinute(5)->by(Str::transliterate('account|'.$email)),
            ];
        });

        RateLimiter::for('public-password-change', function (Request $request): array {
            $userId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');

            return [
                Limit::perMinute(5)->by(Str::transliterate('ip|'.$request->ip())),
                Limit::perMinute(5)->by(Str::transliterate('user|'.$userId)),
            ];
        });

        RateLimiter::for('public-username-change', function (Request $request): array {
            $userId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');

            return [
                Limit::perMinute(5)->by(Str::transliterate('ip|'.$request->ip())),
                Limit::perMinute(3)->by(Str::transliterate('user|'.$userId)),
            ];
        });

        RateLimiter::for('public-email-change', function (Request $request): array {
            $userId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');

            return [
                Limit::perMinute(5)->by(Str::transliterate('ip|'.$request->ip())),
                Limit::perMinute(3)->by(Str::transliterate('user|'.$userId)),
            ];
        });

        RateLimiter::for('two-factor-manage', function (Request $request): array {
            $userId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');

            return [
                Limit::perMinute(5)->by(Str::transliterate('ip|'.$request->ip())),
                Limit::perMinute(5)->by(Str::transliterate('user|'.$userId)),
            ];
        });

        RateLimiter::for('two-factor-challenge', function (Request $request): array {
            $loginId = (string) $request->session()->get(TotpTwoFactorService::SESSION_LOGIN_ID, 'guest');

            return [
                Limit::perMinute(10)->by(Str::transliterate('ip|'.$request->ip())),
                Limit::perMinute(5)->by(Str::transliterate('login|'.$loginId)),
            ];
        });

        RateLimiter::for('passkeys', function (Request $request): array {
            $userId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');

            return [
                Limit::perMinute(10)->by(Str::transliterate('ip|'.$request->ip())),
                Limit::perMinute(6)->by(Str::transliterate('user|'.$userId)),
            ];
        });

        RateLimiter::for('verification-resend', function (Request $request): array {
            $userId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');

            return [
                Limit::perMinute(3)->by(Str::transliterate('ip|'.$request->ip())),
                Limit::perMinute(2)->by(Str::transliterate('user|'.$userId)),
            ];
        });

        RateLimiter::for('staff-messages', static function (): Limit {
            return Limit::perMinute(30)->by('staff-messages:global');
        });

        // Legacy alias kept for any remaining throttle:public-password-reset references.
        RateLimiter::for('public-password-reset', function (Request $request): array {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by(Str::transliterate('ip|'.$request->ip())),
                Limit::perMinute(3)->by(Str::transliterate('account|'.$email)),
            ];
        });
    }
}
