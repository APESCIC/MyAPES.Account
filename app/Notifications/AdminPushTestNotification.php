<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Test message for an administrator's own devices (#803).
 */
class AdminPushTestNotification extends Notification
{
    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(__('admin.settings.push_notifications.test_push.title'))
            ->body(__('admin.settings.push_notifications.test_push.body'))
            ->data(['url' => route('admin.settings.show', 'push-notifications')]);
    }
}