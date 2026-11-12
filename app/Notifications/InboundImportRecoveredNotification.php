<?php

namespace App\Notifications;

use App\Core\Mail\Concerns\BuildsBrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent once to administrators when email import works again after an incident (#779).
 */
class InboundImportRecoveredNotification extends Notification implements ShouldQueue
{
    use BuildsBrandedMail;
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->brandedMail()
            ->subject(__('mail.inbound_import_recovered.subject'))
            ->greeting(__('mail.inbound_import_recovered.greeting', ['name' => $this->recipientName($notifiable)]))
            ->line(__('mail.inbound_import_recovered.line_working'))
            ->line(__('mail.inbound_import_recovered.line_caught_up'))
            ->action(__('mail.inbound_import_recovered.action'), route('admin.emails.import.index'))
            ->markdown('mail.brand.notification');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'inbound_import_recovered',
            'url' => route('admin.emails.import.index'),
        ];
    }
}
