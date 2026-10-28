<?php

namespace App\Notifications\Auth;

use App\Core\Mail\Concerns\BuildsBrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when local TOTP 2FA is enabled (#231).
 */
class TwoFactorEnabledNotification extends Notification implements ShouldQueue
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
            ->subject(__('mail.auth.two_factor_enabled.subject'))
            ->markdown('mail.auth.security-event', [
                'greeting' => __('mail.auth.two_factor_enabled.greeting', [
                    'name' => $this->recipientName($notifiable),
                ]),
                'intro' => __('mail.auth.two_factor_enabled.intro'),
                'url' => route('profile.edit'),
                'action' => __('mail.auth.two_factor_enabled.action'),
                'outro' => __('mail.auth.two_factor_enabled.outro'),
            ]);
    }
}
