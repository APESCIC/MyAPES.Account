<?php

namespace Plugins\Tickets\Notifications;

use App\Core\Mail\Concerns\BuildsBrandedMail;
use App\Support\WorkflowLabels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Plugins\Tickets\Models\SupportTicket;

/**
 * Staff notice after a guest embed email is verified (#688, #691).
 */
class GuestTicketOpenedStaffNotification extends Notification implements ShouldQueue
{
    use BuildsBrandedMail;
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        private readonly SupportTicket $ticket,
        private readonly string $serviceName,
        private readonly string $showRouteName,
        private readonly string $guestLabel,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->brandedMail()
            ->markdown('mail.brand.notification')
            ->greeting(__('mail.brand.greeting', ['name' => $notifiable->name ?? '']))
            ->subject(__('tickets::embed.mail.staff_subject', [
                'service' => $this->serviceName,
                'id' => $this->ticket->id,
            ]))
            ->line(__('tickets::embed.mail.staff_body', [
                'id' => $this->ticket->id,
                'subject' => $this->ticket->subject,
                'guest' => $this->guestLabel,
            ]))
            ->line(__('mail.ticket_updated.line_status', ['status' => WorkflowLabels::status($this->ticket->status)]))
            ->line(__('mail.ticket_updated.line_priority', ['priority' => WorkflowLabels::priority($this->ticket->priority)]))
            ->action(__('mail.ticket_updated.action'), route($this->showRouteName, $this->ticket));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'opened',
            'service' => (string) $this->ticket->sub_core_key,
            'module' => 'tickets',
            'ticket_id' => $this->ticket->id,
            'subject' => $this->ticket->subject,
            'status' => $this->ticket->status,
            'priority' => $this->ticket->priority,
            'updated_by' => $this->guestLabel,
            'url' => route($this->showRouteName, $this->ticket),
        ];
    }
}
