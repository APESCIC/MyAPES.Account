<?php

namespace App\Notifications\Auth;

use App\Core\Mail\Concerns\BuildsBrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the current (old) address when a secure email change is started (#228).
 * Does not include confirmation tokens.
 */
class EmailChangeStartedNotification extends Notification implements ShouldQueue
{
    use BuildsBrandedMail;
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->brandedMail()
            ->subject(__('mail.auth.email_change_started.subject'))
            ->markdown('mail.auth.security-event', [
                'greeting' => __('mail.auth.email_change_started.greeting', [
                    'name' => $this->recipientName($notifiable),
                ]),
                'intro' => __('mail.auth.email_change_started.intro'),
                'url' => route('profile.edit'),
                'action' => __('mail.auth.email_change_started.action'),
                'outro' => __('mail.auth.email_change_started.outro'),
            ]);
    }
}
