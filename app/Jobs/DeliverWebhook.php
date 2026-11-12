<?php

namespace App\Jobs;

use App\Core\Webhooks\WebhookDelivery;
use App\Core\Webhooks\WebhookDeliveryAttempt;
use App\Core\Webhooks\WebhookEndpoint;
use App\Core\Webhooks\WebhookSignature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one webhook delivery (#642). A failure is retried with exponential
 * backoff, up to 8 tries over about a day; each try is logged. After the last
 * try the delivery fails, and an endpoint that keeps failing is turned off.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_ATTEMPTS = 8;

    /** Seconds to wait after each failed try: 1m, 5m, 30m, 1h, 2h, 6h, 12h (about 22 hours in all), then a final try. */
    public const BACKOFF = [60, 300, 1800, 3600, 7200, 21600, 43200];

    public const DISABLE_AFTER_FAILED_DELIVERIES = 5;

    private const TIMEOUT_SECONDS = 10;

    /** Queue attempts, including releases; the delivery row counts the real tries. */
    public int $tries = self::MAX_ATTEMPTS + 2;

    public int $timeout = 30;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);
        if ($delivery === null || in_array($delivery->status, [WebhookDelivery::SUCCEEDED, WebhookDelivery::FAILED, WebhookDelivery::CANCELLED], true)) {
            return;
        }

        $endpoint = $delivery->endpoint;
        if (! $endpoint->active) {
            $delivery->forceFill(['status' => WebhookDelivery::CANCELLED, 'next_attempt_at' => null, 'last_error' => 'Endpoint turned off'])->save();

            return;
        }

        $attempt = $delivery->attempts + 1;
        [$statusCode, $error, $duration] = $this->send($delivery, $endpoint);

        WebhookDeliveryAttempt::query()->create([
            'webhook_delivery_id' => $delivery->id,
            'attempt' => $attempt,
            'status_code' => $statusCode,
            'duration_ms' => $duration,
            'error' => $error,
        ]);

        if ($error === null) {
            $delivery->forceFill(['status' => WebhookDelivery::SUCCEEDED, 'attempts' => $attempt, 'last_status_code' => $statusCode, 'last_error' => null, 'next_attempt_at' => null, 'delivered_at' => now()])->save();
            $endpoint->forceFill(['consecutive_failures' => 0])->save();

            return;
        }

        if ($attempt >= self::MAX_ATTEMPTS) {
            $delivery->forceFill(['status' => WebhookDelivery::FAILED, 'attempts' => $attempt, 'last_status_code' => $statusCode, 'last_error' => $error, 'next_attempt_at' => null])->save();
            $this->recordFailedDelivery($endpoint);

            return;
        }

        $delay = self::BACKOFF[$attempt - 1] ?? self::BACKOFF[array_key_last(self::BACKOFF)];
        $delivery->forceFill(['status' => WebhookDelivery::RETRYING, 'attempts' => $attempt, 'last_status_code' => $statusCode, 'last_error' => $error, 'next_attempt_at' => now()->addSeconds($delay)])->save();
        $this->release($delay);
    }

    /**
     * @return array{?int, ?string, int}
     */
    private function send(WebhookDelivery $delivery, WebhookEndpoint $endpoint): array
    {
        $body = $delivery->body();
        $started = hrtime(true);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'MyAPES-Account-Webhooks/1',
                    WebhookSignature::HEADER => WebhookSignature::sign($body, $endpoint->secret, now()->getTimestamp()),
                    WebhookSignature::EVENT_HEADER => $delivery->event,
                    WebhookSignature::DELIVERY_HEADER => $delivery->event_id,
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (ConnectionException $exception) {
            return [null, Str::limit('Could not connect: '.$exception->getMessage(), 250), $this->elapsed($started)];
        } catch (Throwable $exception) {
            return [null, Str::limit('Request failed: '.class_basename($exception), 250), $this->elapsed($started)];
        }

        return [$response->status(), $response->successful() ? null : 'HTTP '.$response->status(), $this->elapsed($started)];
    }

    private function recordFailedDelivery(WebhookEndpoint $endpoint): void
    {
        $failures = $endpoint->consecutive_failures + 1;
        $updates = ['consecutive_failures' => $failures];
        if ($failures >= self::DISABLE_AFTER_FAILED_DELIVERIES) {
            $updates += ['active' => false, 'disabled_at' => now(), 'disabled_reason' => 'Turned off after '.$failures.' failed deliveries in a row'];
        }

        $endpoint->forceFill($updates)->save();
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
