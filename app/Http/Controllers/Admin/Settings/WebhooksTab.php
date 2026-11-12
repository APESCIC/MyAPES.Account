<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Core\Api\ApiAbilities;
use App\Core\Webhooks\WebhookDelivery;
use App\Core\Webhooks\WebhookEndpoint;
use App\Core\Webhooks\WebhookEvents;
use App\Http\Controllers\Admin\AdminWebhookController;

/**
 * What Admin → Settings → Webhooks shows (#641, #642): endpoints, the event
 * catalogue and the latest deliveries with every attempt. Secrets are never
 * shown, except a new one, once.
 */
final class WebhooksTab
{
    private const LOG_SIZE = 50;

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        return [
            'endpoints' => WebhookEndpoint::query()->withCount('deliveries')->orderBy('name')->get(),
            'deliveries' => WebhookDelivery::query()->with(['endpoint', 'attemptLog'])->latest('id')->limit(self::LOG_SIZE)->get(),
            'events' => WebhookEvents::catalogue(),
            'organisations' => ApiAbilities::ORGANISATIONS,
            'newSecret' => session(AdminWebhookController::SECRET_FLASH_KEY),
        ];
    }
}
