<?php

namespace Plugins\Tickets\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Plugins\Tickets\Models\SupportTicket;

/**
 * Guest embed confirmation / verified notice (#691).
 */
class GuestTicketConfirmationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly ?string $verifyToken,
        public readonly bool $verified,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reference = '#'.$this->ticket->id;

        if (! $this->verified && $this->verifyToken !== null) {
            $url = route('tickets.embed.verify', ['token' => $this->verifyToken]);

            return (new MailMessage)
                ->subject(__('tickets::embed.mail.confirm_subject', ['reference' => $reference]))
                ->line(__('tickets::embed.mail.confirm_intro', ['reference' => $reference]))
                ->action(__('tickets::embed.mail.confirm_action'), $url)
                ->line(__('tickets::embed.mail.confirm_outro'));
        }

        return (new MailMessage)
            ->subject(__('tickets::embed.mail.verified_subject', ['reference' => $reference]))
            ->line(__('tickets::embed.mail.verified_intro', ['reference' => $reference]))
            ->line(__('tickets::embed.mail.verified_outro'));
    }
}
