<?php

namespace Plugins\Cases\Notifications;

use App\Core\Accounts\User;
use App\Core\Mail\Concerns\BuildsBrandedMail;
use App\Support\WorkflowLabels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Plugins\Cases\Models\ShelterCase;

class ShelterCaseUpdatedNotification extends Notification implements ShouldQueue
{
    use BuildsBrandedMail;
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        private readonly ShelterCase $case,
        private readonly User $actor,
        private readonly string $eventLabel,
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
            ->subject(__('mail.shelter_case_updated.subject', [
                'id' => $this->case->id,
                'event' => $this->eventLabel,
            ]))
            ->line(__('mail.shelter_case_updated.line_body', [
                'id' => $this->case->id,
                'title' => $this->case->title,
                'event' => $this->eventLabel,
                'actor' => $this->actor->name,
            ]))
            ->line(__('mail.shelter_case_updated.line_case_type', ['type' => $this->case->case_type]))
            ->line(__('mail.shelter_case_updated.line_status', ['status' => WorkflowLabels::status($this->case->status)]))
            ->action(__('mail.shelter_case_updated.action'), route('shelter.cases.show', $this->case));
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
            'service' => 'shelter',
            'case_id' => $this->case->id,
            'title' => $this->case->title,
            'case_type' => $this->case->case_type,
            'status' => $this->case->status,
            'updated_by' => $this->actor->name,
            'url' => route('shelter.cases.show', $this->case),
        ];
    }
}
