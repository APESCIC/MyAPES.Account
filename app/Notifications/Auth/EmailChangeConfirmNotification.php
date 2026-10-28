<?php

namespace App\Notifications\Auth;

use App\Core\Mail\Concerns\BuildsBrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the proposed new address with a signed confirmation link (#228).
 */
class EmailChangeConfirmNotification extends Notification implements ShouldQueue
{
    use BuildsBrandedMail;
    use Queueable;

    public function __construct(
        public readonly ?string $recipientName,
        public readonly string $confirmUrl,
        public readonly string $newEmail,
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
        $name = is_string($this->recipientName) && trim($this->recipientName) !== ''
            ? trim($this->recipientName)
            : __('mail.brand.greeting_fallback');

        return $this->brandedMail()
            ->subject(__('mail.auth.email_change_confirm.subject'))
            ->markdown('mail.auth.security-event', [
                'greeting' => __('mail.auth.email_change_confirm.greeting', [
                    'name' => $name,
                ]),
                'intro' => __('mail.auth.email_change_confirm.intro', [
                    'email' => $this->newEmail,
                ]),
                'url' => $this->confirmUrl,
                'action' => __('mail.auth.email_change_confirm.action'),
                'outro' => __('mail.auth.email_change_confirm.outro'),
            ]);
    }
}
