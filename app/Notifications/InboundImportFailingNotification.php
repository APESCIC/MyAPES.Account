<?php

namespace App\Notifications;

use App\Core\Mail\Concerns\BuildsBrandedMail;
use App\Core\Mail\Inbound\Diagnostics\FailureType;
use App\Core\Push\Concerns\SendsWebPush;
use App\Core\Push\PushNotificationTypeKeys;
use App\Core\Push\PushTarget;
use App\Core\Push\WebPushPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent once per incident to administrators when email import stops working (#779).
 * Names the failure type only: no host, username, password or server text.
 */
class InboundImportFailingNotification extends Notification implements ShouldQueue
{
    use BuildsBrandedMail;
    use Queueable;
    use SendsWebPush;

    public function __construct(
        private readonly FailureType $failure,
        private readonly int $failures,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    protected function pushNotificationTypeKey(): string
    {
        return PushNotificationTypeKeys::MAIL_IMPORT_ATTENTION;
    }

    public function via(object $notifiable): array
    {
        return $this->optionalWebPushChannels($notifiable, ['mail'], $this->pushRecipientContext());
    }

    public function toWebPush(object $notifiable): WebPushPayload
    {
        return $this->privateWebPush(new PushTarget(
            'admin.emails.import.index',
            [],
            (string) $this->failures,
        ));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->brandedMail()
            ->subject(__('mail.inbound_import_failing.subject'))
            ->greeting(__('mail.inbound_import_failing.greeting', ['name' => $this->recipientName($notifiable)]))
            ->line(__('mail.inbound_import_failing.line_failing', ['count' => $this->failures]))
            ->line(__('mail.inbound_import_failing.line_reason', ['reason' => $this->failure->message()]))
            ->line(__('mail.inbound_import_failing.line_try', ['hint' => $this->failure->hint()]))
            ->line(__('mail.inbound_import_failing.line_safe'))
            ->action(__('mail.inbound_import_failing.action'), route('admin.emails.import.index'))
            ->markdown('mail.brand.notification');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'inbound_import_failing',
            'failure' => $this->failure->value,
            'url' => route('admin.emails.import.index'),
        ];
    }
}
