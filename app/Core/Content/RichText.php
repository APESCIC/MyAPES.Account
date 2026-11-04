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
        if ($html === null || trim($html) === '') {
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

    private function looksLikeHtml(string $value): bool
    {
        return preg_match('/<\s*\/?\s*[a-z][a-z0-9]*[\s>\/]/i', $value) === 1;
    }
}
