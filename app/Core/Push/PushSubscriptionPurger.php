<?php

namespace App\Core\Push;

use NotificationChannels\WebPush\PushSubscription;

/**
 * Drops every stored device subscription after VAPID keys change (#803).
 */
final class PushSubscriptionPurger
{
    public function purgeAll(): int
    {
        return PushSubscription::query()->delete();
    }
}