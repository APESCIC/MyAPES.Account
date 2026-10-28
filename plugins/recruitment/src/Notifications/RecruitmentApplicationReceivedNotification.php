<?php

namespace Plugins\Recruitment\Notifications;

use App\Core\Mail\Concerns\BuildsBrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Plugins\Recruitment\Models\RecruitmentApplication;

/**
 * Acknowledges a submitted Recruitment application to the applicant (#356).
 */
class RecruitmentApplicationReceivedNotification extends Notification implements ShouldQueue
{
    use BuildsBrandedMail;
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        private readonly RecruitmentApplication $application,
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
        $role = (string) $this->application->recruitmentRole?->title;

        return $this->brandedMail()
            ->markdown('mail.brand.notification')
            ->subject(__('recruitment::mail.received.subject', ['role' => $role]))
            ->greeting(__('mail.brand.greeting', ['name' => $this->recipientName($notifiable)]))
            ->line(__('recruitment::mail.received.line_received', ['role' => $role]))
            ->line(__('recruitment::mail.received.line_next'))
            ->action(
                __('recruitment::mail.action'),
                route('recruitment.applications.show', $this->application),
            );
    }
}
