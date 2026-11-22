<?php

namespace Plugins\Recruitment\Notifications;

use App\Core\Mail\Concerns\BuildsBrandedMail;
use App\Core\Push\Concerns\SendsWebPush;
use App\Core\Push\PushNotificationTypeKeys;
use App\Core\Push\PushTarget;
use App\Core\Push\WebPushPayload;
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
    use SendsWebPush;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        private readonly RecruitmentApplication $application,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    protected function pushNotificationTypeKey(): string
    {
        return PushNotificationTypeKeys::RECRUITMENT_APPLICATION_UPDATE;
    }

    public function via(object $notifiable): array
    {
        return $this->optionalWebPushChannels($notifiable, ['mail'], $this->pushRecipientContext());
    }

    public function toWebPush(object $notifiable): WebPushPayload
    {
        return $this->privateWebPush(new PushTarget(
            'recruitment.applications.show',
            ['recruitmentApplication' => $this->application->id],
            (string) $this->application->id,
            'APES CIC',
        ));
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
