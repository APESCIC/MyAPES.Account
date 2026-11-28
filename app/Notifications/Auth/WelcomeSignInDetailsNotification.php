<?php

namespace App\Notifications\Auth;

use App\Core\Mail\Concerns\BuildsBrandedMail;
use App\Core\Settings\SecuritySettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcome email with username and a one-time temporary password (#940).
 * Sent synchronously so the temporary password is never serialised onto the queue.
 * Preview samples use a placeholder; live mail includes the real password once.
 */
class WelcomeSignInDetailsNotification extends Notification
{
    use BuildsBrandedMail;

    /** Placeholder shown in Admin email preview only (not a real credential). */
    public const PREVIEW_PASSWORD = 'preview';

    public function __construct(
        #[\SensitiveParameter]
        public readonly string $temporaryPassword,
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
        $username = is_string($notifiable->username) && $notifiable->username !== ''
            ? $notifiable->username
            : (string) $notifiable->email;

        return $this->brandedMail()
            ->subject(__('mail.auth.welcome_sign_in.subject'))
            ->markdown('mail.auth.welcome-sign-in', [
                'name' => $this->recipientName($notifiable),
                'username' => $username,
                'temporaryPassword' => $this->temporaryPassword,
                'ttlHours' => app(SecuritySettings::class)->temporaryPasswordTtlHours(),
                'loginUrl' => route('public.login'),
                'supportContact' => config('mail.support_address', 'communications@myapes.me.uk'),
                'privacyUrl' => route('privacy'),
            ]);
    }
}
