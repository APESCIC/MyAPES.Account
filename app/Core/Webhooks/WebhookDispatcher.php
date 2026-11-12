<?php

namespace App\Core\Webhooks;

use App\Jobs\DeliverWebhook;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a change into deliveries for every active endpoint that listens to
 * the event in that organisation (#641), after the database transaction
 * commits, and queues them (#642).
 */
final class WebhookDispatcher
{
    /**
     * @param  list<string>|Closure(): iterable<string>  $organisations  A closure is resolved after commit, when related rows exist.
     * @param  array<string, mixed>  $data
     */
    public function dispatch(string $event, array|Closure $organisations, array $data): void
    {
        DB::afterCommit(function () use ($event, $organisations, $data): void {
            $targets = $organisations instanceof Closure ? $organisations() : $organisations;
            $targets = array_values(array_unique(array_filter(array_map('strval', is_array($targets) ? $targets : iterator_to_array($targets, false)))));
            if ($targets === []) {
                return;
            }

            $eventId = (string) Str::uuid();
            foreach ($targets as $organisation) {
                try {
                    $endpoints = WebhookEndpoint::query()->listeningFor($event, $organisation)->get();
                } catch (QueryException) {
                    // Before the webhook tables are migrated (older data migrations), nothing listens yet.
                    return;
                }

                foreach ($endpoints as $endpoint) {
                    $this->queue($endpoint, $eventId, $event, [
                        'id' => $eventId,
                        'type' => $event,
                        'created_at' => now()->toIso8601String(),
                        'organisation' => $organisation,
                        'data' => $data,
                    ]);
                }
            }
        });
    }

    /**
     * Sends the same event again: a new delivery with the same event id and payload.
     */
    public function replay(WebhookDelivery $delivery): WebhookDelivery
    {
        return $this->queue($delivery->endpoint, $delivery->event_id, $delivery->event, $delivery->payload, $delivery->id);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function queue(WebhookEndpoint $endpoint, string $eventId, string $event, array $payload, ?int $replayOf = null): WebhookDelivery
    {
        $delivery = WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $endpoint->id,
            'event_id' => $eventId,
            'event' => $event,
            'payload' => $payload,
            'status' => WebhookDelivery::PENDING,
            'replay_of_id' => $replayOf,
        ]);

        DeliverWebhook::dispatch($delivery->id);

        return $delivery;
    }
}
