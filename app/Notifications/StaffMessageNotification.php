<?php

namespace App\Notifications;

use App\Core\Accounts\User;
use App\Core\Content\RichText;
use App\Core\Mail\Concerns\BuildsBrandedMail;
use App\Core\Mail\Concerns\RespectsMailPreference;
use App\Core\Mail\StaffMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Symfony\Component\Mime\Email;

/**
 * Delivers one staff-authored campaign message (#505–#506).
 *
 * Implements ShouldQueue for the outbound mail matrix. SendStaffMessageJob uses
 * Notification::sendNow so campaign counts still reflect delivery outcomes.
 */
class StaffMessageNotification extends Notification implements ShouldQueue
{
    use BuildsBrandedMail;
    use Queueable;
    use RespectsMailPreference;

    public function __construct(
        private readonly StaffMessage $message,
        private readonly User $sender,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if ($this->message->isOptional()) {
            return $this->optionalMailChannels($notifiable);
        }

        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $body = app(RichText::class)->clean($this->message->body_html) ?? '';

        $mail = $this->brandedMail()
            ->from(
                (string) config('mail.from.address'),
                $this->sender->name ?: (string) config('mail.from.name'),
            )
            ->subject($this->message->subject)
            ->greeting(__('mail.brand.greeting', ['name' => $this->recipientName($notifiable)]))
            ->line(new HtmlString($body))
            ->line(__('mail.brand.footer.no_reply'))
            ->line(__('mail.brand.footer.log_in_to_reply'))
            ->action(__('mail.brand.custom_body_action'), route('login'))
            ->markdown('mail.brand.notification');

        if ($this->message->isOptional() && $notifiable instanceof User) {
            $unsubscribeUrl = URL::signedRoute('mail.unsubscribe', ['user' => $notifiable->id]);

            $mail = $mail->withSymfonyMessage(static function (Email $email) use ($unsubscribeUrl): void {
                $email->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$unsubscribeUrl.'>');
                $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
        }

        return $mail;
    }
}
