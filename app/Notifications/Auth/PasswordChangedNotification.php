<?php

namespace App\Notifications\Auth;

use App\Core\Mail\Concerns\BuildsBrandedMail;
use App\Core\Push\Concerns\SendsAccountSecurityWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification implements ShouldQueue
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
            ->subject(__('mail.auth.password_changed.subject'))
            ->markdown('mail.auth.password-changed', [
                'url' => route('public.login'),
                'name' => $this->recipientName($notifiable),
            ]);
    }
}
