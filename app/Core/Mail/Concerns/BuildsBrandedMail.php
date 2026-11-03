<?php

namespace App\Core\Mail\Concerns;

use App\Core\Mail\ReplyLink;
use Illuminate\Notifications\Messages\MailMessage;

trait BuildsBrandedMail
{
    protected function brandedMail(): MailMessage
    {
        return (new MailMessage)
            ->from(
                (string) config('mail.from.address'),
                __('terms.app_name'),
            );
    }

    protected function replyLink(object $notifiable, string $itemUrl): string
    {
        return app(ReplyLink::class)->to($notifiable, $itemUrl);
    }

    protected function recipientName(object $notifiable): string
    {
        $name = $notifiable->name ?? null;

        return is_string($name) && trim($name) !== ''
            ? trim($name)
            : __('mail.brand.greeting_fallback');
    }
}
