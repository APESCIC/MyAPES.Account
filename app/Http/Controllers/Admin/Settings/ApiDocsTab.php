<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Core\Api\ApiRoutes;
use App\Core\Webhooks\WebhookEvents;
use App\Core\Webhooks\WebhookSignature;
use App\Jobs\DeliverWebhook;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * Admin → Settings → API docs (#643): the /api/v1 endpoints with their
 * abilities, the webhook events and how to check a signature. The full
 * OpenAPI file lives in the repository (release packages leave docs/ out).
 */
final class ApiDocsTab
{
    public const SPEC_URL = 'https://github.com/APESCIC/MyAPES.Account/blob/main/docs/api/openapi-v1.yaml';

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        $endpoints = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var LaravelRoute $route */
            if (! str_starts_with($route->uri(), ApiRoutes::PREFIX.'/')) {
                continue;
            }

            $ability = null;
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'abilities:')) {
                    $ability = substr($middleware, strlen('abilities:'));
                }
            }

            $endpoints[] = [
                'method' => implode(', ', array_diff($route->methods(), ['HEAD'])),
                'path' => '/'.$route->uri(),
                'ability' => $ability,
            ];
        }
        usort($endpoints, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return [
            'endpoints' => $endpoints,
            'specUrl' => self::SPEC_URL,
            'events' => WebhookEvents::catalogue(),
            'signatureHeader' => WebhookSignature::HEADER,
            'eventHeader' => WebhookSignature::EVENT_HEADER,
            'eventIdHeader' => WebhookSignature::DELIVERY_HEADER,
            'tolerance' => WebhookSignature::TOLERANCE_SECONDS,
            'maxAttempts' => DeliverWebhook::MAX_ATTEMPTS,
            'disableAfter' => DeliverWebhook::DISABLE_AFTER_FAILED_DELIVERIES,
        ];
    }
}
