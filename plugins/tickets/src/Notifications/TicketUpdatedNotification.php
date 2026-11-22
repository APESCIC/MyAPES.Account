<?php

namespace Plugins\Tickets\Notifications;

use App\Core\Accounts\User;
use App\Core\Mail\Concerns\BuildsBrandedMail;
use App\Core\Mail\Concerns\MentionsAttachments;
use App\Core\Mail\Concerns\OffersEmailReply;
use App\Core\Push\Concerns\SendsWebPush;
use App\Core\Push\PushRecipientContext;
use App\Core\Push\PushTarget;
use App\Core\Push\WebPushPayload;
use App\Support\WorkflowLabels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\TicketsArea;

class TicketUpdatedNotification extends Notification implements ShouldQueue
{
    use BuildsBrandedMail;
    use MentionsAttachments;
    use OffersEmailReply;
    use Queueable;
    use SendsWebPush;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        private readonly SupportTicket $ticket,
        private readonly User $actor,
        private readonly string $eventLabel,
        private readonly string $subCoreKey,
        private readonly string $showRouteName,
        private readonly string $serviceName,
        private readonly int $attachmentCount = 0,
        private readonly ?string $pushTypeKey = null,
    ) {
        $this->afterCommit();
    }

    protected function pushNotificationTypeKey(): string
    {
        return (string) $this->pushTypeKey;
    }

    public function pushTypeKey(): ?string
    {
        return $this->pushTypeKey;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'mail'];

        if ($this->pushTypeKey === null) {
            return $channels;
        }

        $area = TicketsArea::forModule($this->subCoreKey);
        $context = new PushRecipientContext(
            $this->pushTypeKey,
            $this->actor->id,
            $area->permission('view-all'),
        );

        return $this->optionalWebPushChannels($notifiable, $channels, $context);
    }

    public function toWebPush(object $notifiable): WebPushPayload
    {
        return $this->privateWebPush(new PushTarget(
            $this->showRouteName,
            ['ticket' => $this->ticket->id],
            (string) $this->ticket->id,
            $this->serviceName,
        ));
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = $this->brandedMail()
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
            ->line(__('mail.ticket_updated.line_priority', ['priority' => WorkflowLabels::priority($this->ticket->priority)]));

        return $this->withEmailReply($this->withAttachmentLine($mail, $this->attachmentCount), $notifiable, 'T', $this->ticket)
            ->action(__('mail.ticket_updated.action'), $this->replyLink($notifiable, route($this->showRouteName, $this->ticket)));
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