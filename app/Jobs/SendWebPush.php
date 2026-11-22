<?php

namespace App\Jobs;

use App\Core\Accounts\User;
use App\Core\Push\WebPushDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\PushSubscription;
use Throwable;

/**
 * Delivers one web push to one device subscription (#800).
 */
class SendWebPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    /** Queue releases for retries; real HTTP attempts are capped in config. */
    public int $tries = 6;

    public function __construct(
        public Model $notifiable,
        public Notification $notification,
        public readonly int $pushSubscriptionId,
    ) {
        $this->onQueue('push');
    }

    public function handle(WebPushDeliveryService $delivery): void
    {
        $subscription = PushSubscription::query()->find($this->pushSubscriptionId);

        if ($subscription === null) {
            return;
        }

        if ($this->notifiable instanceof User && $this->notifiable->suspended_at !== null) {
            $subscription->delete();

            return;
        }

        try {
            $result = $delivery->send($this->notifiable, $this->notification, $subscription, $this->attempts());
        } catch (Throwable $throwable) {
            Log::warning('Web push delivery failed before an HTTP response.', [
                'notification' => $this->notification::class,
                'subscription_id' => $this->pushSubscriptionId,
                'exception' => $throwable::class,
            ]);

            if ($this->attempts() < (int) config('myapes.push.max_attempts', 3)) {
                $this->release(60);
            }

            return;
        }

        if ($result['retry']) {
            if ($this->attempts() >= (int) config('myapes.push.max_attempts', 3)) {
                return;
            }

            $this->release($result['retry_after_seconds'] ?? 60);
        }
    }
}