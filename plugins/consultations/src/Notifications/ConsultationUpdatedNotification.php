<?php

namespace Plugins\Consultations\Notifications;

use App\Core\Accounts\User;
use App\Core\Mail\Concerns\BuildsBrandedMail;
use App\Core\Push\Concerns\SendsWebPush;
use App\Core\Push\PushNotificationTypeKeys;
use App\Core\Push\PushTarget;
use App\Core\Push\WebPushPayload;
use App\Support\WorkflowLabels;
use Plugins\Consultations\Models\PetCareConsultation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConsultationUpdatedNotification extends Notification implements ShouldQueue
{
    use BuildsBrandedMail;
    use Queueable;
    use SendsWebPush;

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
    protected function pushNotificationTypeKey(): string
    {
        return PushNotificationTypeKeys::CONSULTATION_UPDATE;
    }

    public function via(object $notifiable): array
    {
        return $this->optionalWebPushChannels(
            $notifiable,
            ['database', 'mail'],
            $this->pushRecipientContext($this->actor, PetCareConsultation::PERMISSION_VIEW_ALL),
        );
    }

    public function toWebPush(object $notifiable): WebPushPayload
    {
        return $this->privateWebPush(new PushTarget(
            'petcare.consultations.show',
            ['consultation' => $this->consultation->id],
            (string) $this->consultation->id,
            'APES Pet Care Clinic',
        ));
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
            ->line(__('mail.consultation_updated.line_status', ['status' => WorkflowLabels::status($this->consultation->status)]))
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
