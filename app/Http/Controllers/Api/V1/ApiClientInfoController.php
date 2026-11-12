<?php

namespace App\Http\Controllers\Api\V1;

use App\Core\Api\ApiClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me (#638): which client the token belongs to and what it may read,
 * so an app can check its set-up. Needs no ability.
 */
class ApiClientInfoController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var ApiClient $client */
        $client = $request->user();
        $token = $client->currentAccessToken();

        return response()->json(['data' => [
            'client' => $client->name,
            'organisation' => $client->organisation,
            'abilities' => array_values((array) $token->abilities),
            'token_expires_at' => $token->expires_at?->toIso8601String(),
        ]]);
    }
}
