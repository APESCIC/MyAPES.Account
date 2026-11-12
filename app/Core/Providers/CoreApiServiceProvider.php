<?php

namespace App\Core\Providers;

use App\Core\Api\ApiClient;
use App\Core\Webhooks\CoreWebhookSources;
use App\Core\Webhooks\WebhookDispatcher;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The /api/v1 rate limit (#638): each token has its own budget per minute,
 * set on its API client. Also registers the Core webhook events (#641).
 */
class CoreApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WebhookDispatcher::class);
    }

    public function boot(): void
    {
        CoreWebhookSources::register();

        RateLimiter::for('api-client', static function (Request $request): Limit {
            $client = $request->user();
            if (! $client instanceof ApiClient) {
                return Limit::perMinute(10)->by('api-anonymous|'.$request->ip());
            }

            $tokenId = $client->currentAccessToken()?->getKey() ?? 'none';

            return Limit::perMinute(max(1, $client->rate_limit_per_minute))->by('api-token|'.$tokenId);
        });
    }
}
