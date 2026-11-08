<?php

namespace App\Core\Mail\Concerns;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * "N new attachments – log in to view" (#551). Files are never attached to
 * the email itself.
 */
trait MentionsAttachments
{
    protected function withAttachmentLine(MailMessage $mail, int $count): MailMessage
    {
        return $count > 0
            ? $mail->line(trans_choice('mail.attachments.line_new', $count, ['count' => $count]))
            : $mail;
    }
}
