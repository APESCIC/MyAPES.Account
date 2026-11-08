<?php

namespace App\Core\Attachments;

/**
 * Save confirmation that also says how many files were uploaded (#551, #454).
 */
final class AttachmentFeedback
{
    public static function status(string $message, int $uploaded): string
    {
        return $uploaded > 0
            ? $message.' '.trans_choice('attachments.flash.uploaded', $uploaded, ['count' => $uploaded])
            : $message;
    }
}
