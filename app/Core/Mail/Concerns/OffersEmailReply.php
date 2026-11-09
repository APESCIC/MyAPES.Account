<?php

namespace App\Core\Mail\Concerns;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\InboundReplyRegistry;
use App\Core\Mail\Inbound\MailReplyThread;
use App\Core\Mail\Inbound\ReplyToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Lets a ticket or case email be answered by replying to it (#578).
 *
 * When replies by email are on, the message gets a per-recipient Reply-To
 * token, a [MYAPES-T-1234] subject tag, a stored Message-ID and a "reply
 * above this line" marker. Otherwise it keeps the no-reply Reply-To.
 */
trait OffersEmailReply
{
    protected function withEmailReply(MailMessage $mail, object $notifiable, string $prefix, Model $record): MailMessage
    {
        $mail->withSymfonyMessage(static function (Email $email): void {
            $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
            $email->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
        });

        $settings = app(InboundMailSettings::class);
        $recordId = $record->getKey();

        if (! $notifiable instanceof User
            || ! is_numeric($recordId)
            || ! $record->exists
            || ! $settings->repliesEnabled()
            || ! app(InboundReplyRegistry::class)->offersRepliesFor($prefix, $record)) {
            return $mail;
        }

        $recordId = (int) $recordId;
        $userId = (int) $notifiable->getKey();
        $replyTo = $settings->replyAddress($prefix, $recordId, $userId);
        $tag = ReplyToken::subjectTag($prefix, $recordId);
        $messageId = 'myapes.'.strtolower($prefix).$recordId.'.'.Str::lower(Str::random(24)).'@'.$settings->messageDomain();

        $mail->viewData = [...$mail->viewData, 'replyByEmail' => true];
        $mail->line(__('mail.reply_by_email.line'));

        return $mail->withSymfonyMessage(static function (Email $email) use ($replyTo, $tag, $messageId, $prefix, $recordId, $userId): void {
            $email->replyTo(new Address($replyTo, __('terms.app_name')));

            if (! str_contains((string) $email->getSubject(), $tag)) {
                $email->subject(trim((string) $email->getSubject().' '.$tag));
            }

            $headers = $email->getHeaders();
            $headers->remove('Message-ID');
            $headers->addIdHeader('Message-ID', $messageId);

            MailReplyThread::query()->create([
                'message_id' => $messageId,
                'record_prefix' => strtoupper($prefix),
                'record_id' => $recordId,
                'user_id' => $userId,
                'created_at' => now(),
            ]);
        });
    }
}
