<?php

namespace App\Http\Middleware;

use App\Core\Api\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After auth:sanctum on /api/v1 (#638): the token must belong to a client
 * that is not revoked, and the caller's IP must be on its allowlist if it has one.
 */
class AuthenticateApiClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->user();

        if (! $client instanceof ApiClient || $client->isRevoked()) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! $client->allowsIp($request->ip())) {
            return response()->json(['message' => 'This IP address is not allowed for this API client.'], Response::HTTP_FORBIDDEN);
        }

        $client->recordUse($request->ip());

        return $next($request);
    }
}
