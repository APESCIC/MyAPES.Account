<?php

namespace App\Core\Mail\Inbound\Parsing;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use EmailReplyParser\Parser\EmailParser;

/**
 * Pulls the new text out of an email reply (#581): cut at our "reply above
 * this line" marker, drop quoted text and signatures, and turn HTML-only
 * mail into plain text. The result is plain text; no sender HTML is kept.
 */
final class ReplyTextExtractor
{
    private const MARKER = 'reply above this line';

    public function extract(ParsedInboundEmail $email): string
    {
        $text = trim($email->textBody) !== '' ? $email->textBody : $this->htmlToText($email->htmlBody);
        $text = $this->normalise($text);
        $text = $this->cutAtMarker($text);
        $text = $this->cutAtForwardedHeader($text);
        $text = (new EmailParser)->parse($text)->getVisibleText();
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", $this->normalise($text)));

        return mb_substr($text, 0, max(1, (int) config('inbound-mail.max_reply_characters', 20000)));
    }

    private function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return implode("\n", array_map('rtrim', explode("\n", $text)));
    }

    private function cutAtMarker(string $text): string
    {
        $lines = explode("\n", $text);

        foreach ($lines as $index => $line) {
            if (str_contains(strtolower($line), self::MARKER)) {
                $kept = array_slice($lines, 0, $index);

                while ($kept !== [] && preg_match('/^\s*>?\s*$/', (string) end($kept)) === 1) {
                    array_pop($kept);
                }

                if ($kept !== [] && preg_match('/^(on\s.+|.+\swrote:?|.+\sa écrit\s?:?)$/i', trim((string) end($kept))) === 1) {
                    array_pop($kept);
                }

                return implode("\n", $kept);
            }
        }

        return $text;
    }

    /**
     * Outlook and some webmail put the original under a header block or a
     * rule instead of quoting it with ">".
     */
    private function cutAtForwardedHeader(string $text): string
    {
        $lines = explode("\n", $text);

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if (preg_match('/^_{10,}$/', $trimmed) === 1 || preg_match('/^-{2,}\s*original message\s*-{2,}$/i', $trimmed) === 1) {
                return implode("\n", array_slice($lines, 0, $index));
            }

            if (preg_match('/^\*?from:\*?\s/i', $trimmed) === 1) {
                $next = strtolower(implode("\n", array_slice($lines, $index + 1, 4)));

                if (preg_match('/^\*?(sent|date):\*?\s/m', $next) === 1) {
                    return implode("\n", array_slice($lines, 0, $index));
                }
            }
        }

        return $text;
    }

    private function htmlToText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $quoted = $xpath->query(
            '//head|//script|//style|//blockquote'
            .'|//*[contains(concat(" ", normalize-space(@class), " "), " gmail_quote ")]'
            .'|//*[contains(concat(" ", normalize-space(@class), " "), " yahoo_quoted ")]'
            .'|//*[@id="divRplyFwdMsg"]|//*[@id="appendonsend"]'
        );

        foreach ($quoted === false ? [] : iterator_to_array($quoted) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $body = $document->getElementsByTagName('body')->item(0) ?? $document->documentElement;

        return $body === null ? '' : html_entity_decode($this->nodeText($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function nodeText(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return (string) preg_replace('/\s+/', ' ', (string) $node->nodeValue);
        }

        $text = '';

        foreach ($node->childNodes as $child) {
            $text .= $this->nodeText($child);
        }

        if (! $node instanceof DOMElement) {
            return $text;
        }

        return match (strtolower($node->tagName)) {
            'br' => "\n",
            'p', 'div', 'li', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'table', 'ul', 'ol' => "\n".trim($text, ' ')."\n",
            'hr' => "\n",
            default => $text,
        };
    }
}
