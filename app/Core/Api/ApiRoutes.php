<?php

namespace App\Core\Api;

use Closure;
use Illuminate\Support\Facades\Route;

/**
 * The /api/v1 route group (#638). Core and plugins add their endpoints with
 * ApiRoutes::v1(), so every endpoint gets the same token, client, IP and
 * rate-limit checks. Each route still names its ability with abilities:<name>.
 */
final class ApiRoutes
{
    public const PREFIX = 'api/v1';

    public const NAME = 'api.v1.';

    /** @var list<string> */
    public const MIDDLEWARE = ['api', 'auth:sanctum', 'api.client', 'throttle:api-client'];

    public static function v1(Closure $routes): void
    {
        Route::prefix(self::PREFIX)->name(self::NAME)->middleware(self::MIDDLEWARE)->group($routes);
    }
}
