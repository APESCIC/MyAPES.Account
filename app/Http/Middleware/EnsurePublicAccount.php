<?php

namespace App\Http\Middleware;

use App\Core\Accounts\AccountClassifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep staff-side accounts out of client self-service routes (#512).
 *
 * Guests pass through so the route's own auth redirect still applies.
 */
class EnsurePublicAccount
{
    public function __construct(
        private readonly AccountClassifier $classifier,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $this->classifier->isStaffSide($user)) {
            return response()->view('errors.public-account-required', [], 403);
        }

        return $next($request);
    }
}
