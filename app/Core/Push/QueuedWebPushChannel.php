<?php

namespace App\Core\Push;

use App\Jobs\SendWebPush;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Queues push delivery on the push worker instead of sending synchronously (#800).
 */
class QueuedWebPushChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof Model || ! method_exists($notifiable, 'routeNotificationFor')) {
            return;
        }

        /** @var Collection<int, \NotificationChannels\WebPush\PushSubscription> $subscriptions */
        $subscriptions = $notifiable->routeNotificationFor('WebPush', $notification);

        if ($subscriptions->isEmpty() || ! method_exists($notification, 'toWebPush')) {
            return;
        }

        foreach ($subscriptions as $subscription) {
            SendWebPush::dispatch(
                $notifiable,
                $notification,
                (int) $subscription->getKey(),
            )->afterCommit();
        }
    }
}