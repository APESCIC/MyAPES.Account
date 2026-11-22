<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Test message for a single profile device (#795).
 */
class ProfilePushTestNotification extends Notification
{
    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(__('push.profile.test_title'))
            ->body(__('push.profile.test_body'))
            ->data(['url' => route('profile.edit').'#notifications']);
    }
}
