<?php

namespace App\Notifications;

use App\Core\Accounts\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PendingFirstLoginChaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly User $actor,
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
        $staffLoginUrl = route('staff.login');

        return (new MailMessage)
            ->subject(__('mail.pending_first_login.subject'))
            ->greeting(__('mail.pending_first_login.greeting', ['name' => $notifiable->name]))
            ->line(__('mail.pending_first_login.line_request'))
            ->line(__('mail.pending_first_login.line_cloudron'))
            ->action(__('mail.pending_first_login.action'), $staffLoginUrl)
            ->line(__('mail.pending_first_login.line_fallback', ['url' => $staffLoginUrl]))
            ->salutation(__('mail.pending_first_login.salutation'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'pending_first_login_chase',
            'chased_by' => $this->actor->id,
            'url' => route('staff.login'),
        ];
    }
}
