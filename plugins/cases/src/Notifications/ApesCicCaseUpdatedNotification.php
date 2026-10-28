<?php

namespace Plugins\Cases\Notifications;

use App\Core\Accounts\User;
use App\Core\Mail\Concerns\BuildsBrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Plugins\Cases\Models\ShelterCase;

class ApesCicCaseUpdatedNotification extends Notification implements ShouldQueue
{
    use BuildsBrandedMail;
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        private readonly ShelterCase $case,
        private readonly User $actor,
        private readonly string $eventLabel,
        private readonly string $subCoreKey,
        private readonly string $showRouteName,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->brandedMail()
            ->markdown('mail.brand.notification')
            ->greeting(__('mail.brand.greeting', ['name' => $this->recipientName($notifiable)]))
            ->subject(__('mail.case_updated.subject', [
                'id' => $this->case->id,
                'event' => $this->eventLabel,
            ]))
            ->line(__('mail.case_updated.line_body', [
                'id' => $this->case->id,
                'event' => $this->eventLabel,
                'actor' => $this->actor->name,
            ]))
            ->line(__('mail.case_updated.line_status', ['status' => $this->case->status]))
            ->line(__('mail.case_updated.line_priority', ['priority' => $this->case->priority]))
            ->action(__('mail.case_updated.action'), route($this->showRouteName, $this->case));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->eventLabel,
            'service' => $this->subCoreKey,
            'module' => 'cases',
            'case_id' => $this->case->id,
            'status' => $this->case->status,
            'priority' => $this->case->priority,
            'updated_by' => $this->actor->name,
            'url' => route($this->showRouteName, $this->case),
        ];
    }
}
