<?php

namespace App\Http\Middleware;

use App\Core\Accounts\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects invited accounts with a temporary password to the forced-change screen (#941).
 */
class EnsurePasswordChanged
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->mustChangePassword()) {
            return $next($request);
        }

        if ($request->routeIs([
            'password.forced-change.edit',
            'password.forced-change.update',
            'auth.logout',
            'health',
        ])) {
            return $next($request);
        }

        return redirect()->route('password.forced-change.edit');
    }
}
