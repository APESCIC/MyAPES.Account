<?php

namespace App\Notifications\Auth;

use App\Core\Mail\Concerns\BuildsBrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Optional 72-hour set-password link for staff-created public clients (#671).
 * Never includes a password or PIN.
 */
class StaffAssistedSetPasswordNotification extends Notification implements ShouldQueue
{
    use BuildsBrandedMail;
    use Queueable;

    public function __construct(
        #[\SensitiveParameter]
        public string $token,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expireMinutes = (int) config('auth.passwords.staff_assisted.expire', 4320);

        return $this->brandedMail()
            ->subject(__('mail.auth.set_password.subject'))
            ->markdown('mail.auth.set-password', [
                'url' => $this->setPasswordUrl($notifiable),
                'name' => $this->recipientName($notifiable),
                'expireMinutes' => $expireMinutes,
            ]);
    }

    protected function setPasswordUrl(object $notifiable): string
    {
        return url(route('password.set', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
    }
}
