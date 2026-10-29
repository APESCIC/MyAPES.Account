<?php

namespace Plugins\Tickets\Notifications;

use App\Core\Accounts\User;
use App\Core\Mail\Concerns\BuildsBrandedMail;
use App\Support\WorkflowLabels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Plugins\Tickets\Models\SupportTicket;

class TicketUpdatedNotification extends Notification implements ShouldQueue
{
    use BuildsBrandedMail;
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        private readonly SupportTicket $ticket,
        private readonly User $actor,
        private readonly string $eventLabel,
        private readonly string $subCoreKey,
        private readonly string $showRouteName,
        private readonly string $serviceName,
    ) {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return $this->brandedMail()
            ->markdown('mail.brand.notification')
            ->greeting(__('mail.brand.greeting', ['name' => $this->recipientName($notifiable)]))
            ->subject(__('mail.ticket_updated.subject', [
                'service' => $this->serviceName,
                'id' => $this->ticket->id,
                'event' => $this->eventLabel,
            ]))
            ->line(__('mail.ticket_updated.line_body', [
                'id' => $this->ticket->id,
                'subject' => $this->ticket->subject,
                'event' => $this->eventLabel,
                'actor' => $this->actor->name,
            ]))
            ->line(__('mail.ticket_updated.line_status', ['status' => WorkflowLabels::status($this->ticket->status)]))
            ->line(__('mail.ticket_updated.line_priority', ['priority' => WorkflowLabels::priority($this->ticket->priority)]))
            ->action(__('mail.ticket_updated.action'), route($this->showRouteName, $this->ticket));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->eventLabel,
            'service' => $this->subCoreKey,
            'module' => 'tickets',
            'ticket_id' => $this->ticket->id,
            'subject' => $this->ticket->subject,
            'status' => $this->ticket->status,
            'priority' => $this->ticket->priority,
            'updated_by' => $this->actor->name,
            'url' => route($this->showRouteName, $this->ticket),
        ];
    }
}
