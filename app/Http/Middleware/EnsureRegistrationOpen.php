<?php

namespace App\Http\Middleware;

use App\Core\Settings\GeneralSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the public Register page and form when an admin switches public
 * registration off on Admin → Settings → General (#739). Staff sign-in
 * through Cloudron is never affected.
 */
class EnsureRegistrationOpen
{
    public function __construct(
        private readonly GeneralSettings $general,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->general->registrationOpen()) {
            return redirect()->route('public.login')->with('status', __('auth.common.registration_closed'));
        }

        return $next($request);
    }
}
