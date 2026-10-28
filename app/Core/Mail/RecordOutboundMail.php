<?php

namespace App\Core\Mail;

use App\Core\Accounts\User;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Writes one metadata row per outbound mail notification (#359).
 */
final class RecordOutboundMail
{
    public function __construct(
        private readonly MailTemplateRegistry $registry,
    ) {}

    public function sent(NotificationSent $event): void
    {
        $this->record($event->channel, $event->notifiable, $event->notification, OutboundMailLog::STATUS_SENT);
    }

    public function failed(NotificationFailed $event): void
    {
        $this->record($event->channel, $event->notifiable, $event->notification, OutboundMailLog::STATUS_FAILED);
    }

    private function record(string $channel, object $notifiable, object $notification, string $status): void
    {
        if ($channel !== 'mail') {
            return;
        }

        $address = $this->address($notifiable, $notification);

        OutboundMailLog::query()->create([
            'template_key' => $this->registry->forNotification($notification)?->key,
            'notification_type' => $notification::class,
            'mailer' => (string) config('mail.default'),
            'user_id' => $notifiable instanceof User ? $notifiable->getKey() : null,
            'recipient_hash' => $address === null ? null : OutboundMailLog::hashRecipient($address),
            'recipient_domain' => $address === null ? null : $this->domain($address),
            'status' => $status,
            'created_at' => now(),
        ]);
    }

    private function address(object $notifiable, object $notification): ?string
    {
        if (! method_exists($notifiable, 'routeNotificationFor')) {
            return null;
        }

        $route = $notifiable->routeNotificationFor('mail', $notification);

        if (is_array($route)) {
            $first = array_key_first($route);
            $route = is_string($first) ? $first : ($route[$first] ?? null);
        }

        return is_string($route) && $route !== '' ? $route : null;
    }

    private function domain(string $address): ?string
    {
        $at = strrpos($address, '@');

        return $at === false ? null : strtolower(substr($address, $at + 1));
    }
}
