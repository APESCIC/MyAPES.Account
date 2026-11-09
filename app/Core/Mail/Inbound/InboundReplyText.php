<?php

namespace App\Core\Mail\Inbound;

/**
 * Turns an extracted plain-text reply into the escaped paragraphs that
 * ticket messages and case updates store (#582). No sender HTML survives.
 */
final class InboundReplyText
{
    public static function toHtml(string $text): string
    {
        $paragraphs = preg_split('/\n\s*\n/', trim(str_replace(["\r\n", "\r"], "\n", $text))) ?: [];

        return implode('', array_map(
            static fn (string $paragraph): string => '<p>'.nl2br(e(trim($paragraph)), false).'</p>',
            array_filter($paragraphs, static fn (string $paragraph): bool => trim($paragraph) !== ''),
        ));
    }
}
