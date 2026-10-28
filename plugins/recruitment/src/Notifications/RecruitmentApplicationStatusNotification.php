<?php

namespace Plugins\Recruitment\Notifications;

use App\Core\Mail\Concerns\BuildsBrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;
use Plugins\Recruitment\Models\RecruitmentApplication;

/**
 * Tells the applicant about a Recruitment decision (#356). Staff notes are never included.
 */
class RecruitmentApplicationStatusNotification extends Notification implements ShouldQueue
{
    use BuildsBrandedMail;
    use Queueable;

    public const NOTIFIED_STATUSES = [
        RecruitmentApplication::STATUS_SHORTLISTED,
        RecruitmentApplication::STATUS_REJECTED,
        RecruitmentApplication::STATUS_ACCEPTED,
        RecruitmentApplication::STATUS_CLOSED,
    ];

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        private readonly RecruitmentApplication $application,
        public readonly string $status,
    ) {
        if (! in_array($status, self::NOTIFIED_STATUSES, true)) {
            throw new InvalidArgumentException("Recruitment status [{$status}] does not send applicant mail.");
        }

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
            ->subject($this->subject($role))
            ->greeting(__('mail.brand.greeting', ['name' => $this->recipientName($notifiable)]))
            ->line($this->body($role))
            ->action(
                __('recruitment::mail.action'),
                route('recruitment.applications.show', $this->application),
            );
    }

    private function subject(string $role): string
    {
        return match ($this->status) {
            RecruitmentApplication::STATUS_SHORTLISTED => __('recruitment::mail.status.shortlisted.subject', ['role' => $role]),
            RecruitmentApplication::STATUS_REJECTED => __('recruitment::mail.status.rejected.subject', ['role' => $role]),
            RecruitmentApplication::STATUS_ACCEPTED => __('recruitment::mail.status.accepted.subject', ['role' => $role]),
            RecruitmentApplication::STATUS_CLOSED => __('recruitment::mail.status.closed.subject', ['role' => $role]),
        };
    }

    private function body(string $role): string
    {
        return match ($this->status) {
            RecruitmentApplication::STATUS_SHORTLISTED => __('recruitment::mail.status.shortlisted.line', ['role' => $role]),
            RecruitmentApplication::STATUS_REJECTED => __('recruitment::mail.status.rejected.line', ['role' => $role]),
            RecruitmentApplication::STATUS_ACCEPTED => __('recruitment::mail.status.accepted.line', ['role' => $role]),
            RecruitmentApplication::STATUS_CLOSED => __('recruitment::mail.status.closed.line', ['role' => $role]),
        };
    }
}
