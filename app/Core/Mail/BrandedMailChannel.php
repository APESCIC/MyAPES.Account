<?php

namespace App\Core\Mail;

use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail channel that applies admin-edited template bodies (#486). Only
 * notifications with a stored body are wrapped; everything else is sent
 * exactly as Laravel would.
 */
class BrandedMailChannel extends MailChannel
{
    public function send($notifiable, Notification $notification)
    {
        $template = app(MailTemplateRegistry::class)->forNotification($notification);
        $locale = (string) config('app.locale');
        $overrides = app(MailCopyOverrides::class);

        if ($template === null || $overrides->bodyFor($template, $locale) === null) {
            return parent::send($notifiable, $notification);
        }

        return parent::send($notifiable, new class($notification, static fn (MailMessage $message): MailMessage => $overrides->applyBody($message, $template, $notifiable, $locale)) extends Notification
        {
            public function __construct(
                private readonly Notification $inner,
                private readonly \Closure $transform,
            ) {
                $this->id = $inner->id;
                $this->locale = $inner->locale;
            }

            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): mixed
            {
                $message = $this->inner->toMail($notifiable);

                return $message instanceof MailMessage ? ($this->transform)($message) : $message;
            }
        });
    }
}
