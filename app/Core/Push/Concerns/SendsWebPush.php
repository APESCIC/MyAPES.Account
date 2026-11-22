<?php

namespace App\Core\Push\Concerns;

use App\Core\Accounts\User;
use App\Core\Push\PushRecipientContext;
use App\Core\Push\PushRecipientFilter;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Adds web push to via() without changing mail or database output (#796).
 */
trait SendsWebPush
{
    abstract protected function pushNotificationTypeKey(): string;

    protected function pushRecipientContext(
        ?User $actor = null,
        ?string $divisionPermission = null,
    ): PushRecipientContext {
        return new PushRecipientContext(
            $this->pushNotificationTypeKey(),
            $actor?->id,
            $divisionPermission,
        );
    }

    /**
     * @param  list<class-string|string>  $channels
     * @return list<class-string|string>
     */
    protected function optionalWebPushChannels(
        object $notifiable,
        array $channels,
        PushRecipientContext $context,
    ): array {
        $withoutPush = array_values(array_filter(
            $channels,
            static fn (string $channel): bool => ! in_array($channel, [WebPushChannel::class, 'WebPush'], true),
        ));

        if (! $notifiable instanceof User) {
            return $withoutPush;
        }

        if (! app(PushRecipientFilter::class)->allows($notifiable, $context)) {
            return $withoutPush;
        }

        return [...$withoutPush, WebPushChannel::class];
    }
}