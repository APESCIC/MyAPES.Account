<?php

namespace App\Notifications;

use App\Core\Mail\Concerns\BuildsBrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffClientRecordsArchivedNotification extends Notification implements ShouldQueue
{
    use BuildsBrandedMail;
    use Queueable;

    /**
     * @param  array<string, int>  $counts  archived record counts keyed by record type label
     */
    public function __construct(
        private readonly array $counts,
    ) {
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
        $mail = $this->brandedMail()
            ->subject(__('mail.staff_client_records_archived.subject'))
            ->greeting(__('mail.staff_client_records_archived.greeting', ['name' => $this->recipientName($notifiable)]))
            ->line(__('mail.staff_client_records_archived.line_archived'));

        foreach ($this->counts as $label => $count) {
            $mail->line(__('mail.staff_client_records_archived.line_count', ['label' => $label, 'count' => $count]));
        }

        return $mail
            ->line(__('mail.staff_client_records_archived.line_register'))
            ->line(__('mail.staff_client_records_archived.line_recoverable'))
            ->action(__('mail.staff_client_records_archived.action'), route('public.register'))
            ->markdown('mail.brand.notification');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'staff_client_records_archived',
            'counts' => $this->counts,
            'url' => route('public.register'),
        ];
    }
}
