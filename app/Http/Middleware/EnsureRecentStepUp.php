<?php

namespace App\Http\Middleware;

use App\Core\Accounts\User;
use App\Core\Qa\QaLogin;
use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step-up gate for sensitive account mutations (#233 / #232 / #823).
 *
 * Local identities use Laravel password confirmation (and passkey confirm,
 * which also calls session passwordConfirmed()). Directory identities are
 * sent through Staff OIDC with prompt=login instead. QA accounts (local only)
 * confirm their profile instead of a password (#839).
 */
class EnsureRecentStepUp
{
    public const OIDC_STEP_UP_SESSION_KEY = 'myapes.oidc_step_up';

    public function __construct(
        private readonly RequirePassword $requirePassword,
        private readonly ResponseFactory $responseFactory,
        private readonly UrlGenerator $urlGenerator,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null, ?string $passwordTimeoutSeconds = null): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (
            $user instanceof User
            && QaLogin::enabled()
            && QaLogin::profileFor($user) !== null
            && $this->shouldConfirmPassword($request, $passwordTimeoutSeconds)
        ) {
            if ($request->expectsJson()) {
                return $this->responseFactory->json([
                    'message' => 'Password confirmation required.',
                ], 423);
            }

            $request->session()->flash(QaLogin::CONFIRM, true);
            $request->session()->put('url.intended', $request->fullUrl());

            return $this->responseFactory->redirectGuest(
                $this->urlGenerator->route($redirectToRoute ?? 'password.confirm'),
            );
        }

        if (
            $user instanceof User
            && $user->hasDirectoryIdentity()
            && $this->shouldConfirmPassword($request, $passwordTimeoutSeconds)
        ) {
            if ($request->expectsJson()) {
                return $this->responseFactory->json([
                    'message' => 'Password confirmation required.',
                ], 423);
            }

            $request->session()->put(self::OIDC_STEP_UP_SESSION_KEY, [
                'user_id' => $user->getKey(),
                'oidc_sub' => $user->oidc_sub,
            ]);

            return $this->responseFactory->redirectGuest(
                $this->urlGenerator->route('staff.auth.step-up'),
            );
        }

        return $this->requirePassword->handle(
            $request,
            $next,
            $redirectToRoute,
            $passwordTimeoutSeconds,
        );
    }

    private function shouldConfirmPassword(Request $request, ?string $passwordTimeoutSeconds): bool
    {
        $timeout = $passwordTimeoutSeconds !== null && $passwordTimeoutSeconds !== ''
            ? (int) $passwordTimeoutSeconds
            : (int) config('auth.password_timeout', 10800);

        $confirmedAt = Date::now()->unix() - $request->session()->get('auth.password_confirmed_at', 0);

        return $confirmedAt > $timeout;
    }
}
