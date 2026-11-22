<?php

namespace App\Notifications\Auth;

use App\Core\Mail\Concerns\BuildsBrandedMail;
use App\Core\Push\Concerns\SendsAccountSecurityWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a passkey is removed (#232).
 */
class PasskeyRemovedNotification extends Notification implements ShouldQueue
{
    use BuildsBrandedMail;
    use Queueable;
    use SendsAccountSecurityWebPush;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->accountSecurityVia($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->brandedMail()
            ->subject(__('mail.auth.passkey_removed.subject'))
            ->markdown('mail.auth.security-event', [
                'greeting' => __('mail.auth.passkey_removed.greeting', [
                    'name' => $this->recipientName($notifiable),
                ]),
                'intro' => __('mail.auth.passkey_removed.intro'),
                'url' => route('profile.edit'),
                'action' => __('mail.auth.passkey_removed.action'),
                'outro' => __('mail.auth.passkey_removed.outro'),
            ]);
    }
}
