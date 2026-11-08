<?php

namespace App\Notifications;

use App\Core\Mail\Concerns\BuildsBrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an uploader that a file failed the virus scan (#550). The file itself
 * is never attached.
 */
class AttachmentQuarantinedNotification extends Notification implements ShouldQueue
{
    use BuildsBrandedMail;
    use Queueable;

    public function __construct(
        private readonly string $fileName,
        private readonly string $recordUrl,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->brandedMail()
            ->markdown('mail.brand.notification')
            ->subject(__('mail.attachment_quarantined.subject'))
            ->greeting(__('mail.brand.greeting', ['name' => $this->recipientName($notifiable)]))
            ->line(__('mail.attachment_quarantined.line_body', ['name' => $this->fileName]))
            ->line(__('mail.attachment_quarantined.line_next'))
            ->action(__('mail.attachment_quarantined.action'), $this->replyLink($notifiable, $this->recordUrl));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'attachment_quarantined',
            'file_name' => $this->fileName,
            'url' => $this->recordUrl,
        ];
    }
}
