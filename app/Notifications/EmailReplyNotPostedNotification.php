<?php

namespace App\Notifications;

use App\Core\Mail\Concerns\BuildsBrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * Tells someone their email reply was not added, because the ticket or case
 * is closed (#582) or because it could not be matched to one (#749). Sent at
 * most once an hour per sender and
 * marked as an automatic reply so it never starts a mail loop (#583).
 */
class EmailReplyNotPostedNotification extends Notification implements ShouldQueue
{
    use BuildsBrandedMail;
    use Queueable;

    public const REASON_CLOSED = 'closed';

    public const REASON_UNMATCHED = 'unmatched';

    public function __construct(
        private readonly string $reference,
        private readonly string $recordUrl,
        private readonly string $reason = self::REASON_CLOSED,
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
        $reasonKey = 'mail.reply_not_posted.line_'.$this->reason;

        return $this->brandedMail()
            ->markdown('mail.brand.notification')
            ->subject(__('mail.reply_not_posted.subject', ['reference' => $this->reference]))
            ->greeting(__('mail.brand.greeting', ['name' => $this->recipientName($notifiable)]))
            ->line(__($reasonKey, ['reference' => $this->reference]))
            ->line(__('mail.reply_not_posted.line_next'))
            ->action(__('mail.reply_not_posted.action'), $this->replyLink($notifiable, $this->recordUrl))
            ->withSymfonyMessage(static function (Email $email): void {
                $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-replied');
                $email->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
            });
    }
}
