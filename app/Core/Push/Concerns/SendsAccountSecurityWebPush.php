<?php

namespace App\Core\Push\Concerns;

use App\Core\Push\PushNotificationTypeKeys;
use App\Core\Push\PushTarget;
use App\Core\Push\WebPushPayload;

/**
 * Web push for auth security emails (#798).
 */
trait SendsAccountSecurityWebPush
{
    use SendsWebPush;

    protected function pushNotificationTypeKey(): string
    {
        return PushNotificationTypeKeys::ACCOUNT_SECURITY_CHANGE;
    }

    /**
     * @return list<class-string|string>
     */
    protected function accountSecurityVia(object $notifiable): array
    {
        return $this->optionalWebPushChannels($notifiable, ['mail'], $this->pushRecipientContext());
    }

    public function toWebPush(object $notifiable): WebPushPayload
    {
        return $this->privateWebPush(new PushTarget('profile.edit'));
    }
}