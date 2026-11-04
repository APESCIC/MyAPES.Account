<?php

namespace App\Core\Content;

use Illuminate\Support\HtmlString;
use Mews\Purifier\Facades\Purifier;

/**
 * Rich text is sanitised on save and again on render (#484). Plain text
 * from before the editor existed is escaped and keeps its line breaks.
 */
final class RichText
{
    public function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '' || ! $this->looksLikeHtml($html)) {
            return $html;
        }

        return trim((string) Purifier::clean($html));
    }

    public function render(?string $value): HtmlString
    {
        if ($value === null || trim($value) === '') {
            return new HtmlString('');
        }

        if (! $this->looksLikeHtml($value)) {
            return new HtmlString(nl2br(e($value)));
        }

        return new HtmlString((string) $this->clean($value));
    }

    /**
     * Value for an editor textarea: stored HTML as-is (escaped by Blade),
     * legacy plain text turned into paragraphs so line breaks survive.
     */
    public function forEditor(?string $value): string
    {
        if ($value === null || trim($value) === '' || $this->looksLikeHtml($value)) {
            return (string) $value;
        }

        return '<p>'.str_replace("\n", '<br>', e(str_replace("\r\n", "\n", $value))).'</p>';
    }

    private function looksLikeHtml(string $value): bool
    {
        return preg_match('/<\s*\/?\s*[a-z][a-z0-9]*[\s>\/]/i', $value) === 1;
    }
}
