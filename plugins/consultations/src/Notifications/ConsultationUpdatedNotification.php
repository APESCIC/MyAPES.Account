<?php

namespace Plugins\Consultations\Notifications;

use App\Core\Accounts\User;
use App\Core\Mail\Concerns\BuildsBrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Plugins\Consultations\Models\PetCareConsultation;

class ConsultationUpdatedNotification extends Notification implements ShouldQueue
{
    use BuildsBrandedMail;
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        private readonly PetCareConsultation $consultation,
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
            ->subject(__('mail.consultation_updated.subject', [
                'id' => $this->consultation->id,
                'event' => $this->eventLabel,
            ]))
            ->line(__('mail.consultation_updated.line_body', [
                'id' => $this->consultation->id,
                'subject' => $this->consultation->subject,
                'event' => $this->eventLabel,
                'actor' => $this->actor->name,
            ]))
            ->line(__('mail.consultation_updated.line_status', ['status' => $this->consultation->status]))
            ->action(__('mail.consultation_updated.action'), route('petcare.consultations.show', $this->consultation));
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
            'service' => 'petcare',
            'consultation_id' => $this->consultation->id,
            'subject' => $this->consultation->subject,
            'status' => $this->consultation->status,
            'updated_by' => $this->actor->name,
            'url' => route('petcare.consultations.show', $this->consultation),
        ];
    }
}
