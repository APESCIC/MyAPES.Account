<?php

namespace App\Core\Mail\Inbound\Parsing;

use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Message;

/**
 * Reads a raw RFC 5322 message into the parts replies by email need (#579).
 * MIME decoding is done by webklex/php-imap; order-sensitive headers come
 * from {@see RawHeaders}.
 */
final class InboundEmailParser
{
    public function parse(string $raw): ParsedInboundEmail
    {
        $headers = RawHeaders::fromRaw($raw);

        try {
            $message = Message::fromString($raw);
        } catch (Throwable $exception) {
            throw new UnparseableEmail('The message could not be decoded.', previous: $exception);
        }

        $from = $message->getFrom()->first();
        $html = $this->safeString(fn (): string => $message->hasHTMLBody() ? $message->getHTMLBody() : '');

        return new ParsedInboundEmail(
            headers: $headers,
            messageId: $headers->messageIds('message-id')[0] ?? null,
            fromAddress: $from instanceof Address && is_string($from->mail) && $from->mail !== '' ? strtolower(trim($from->mail)) : null,
            fromName: $from instanceof Address && is_string($from->personal) && trim($from->personal) !== '' ? trim($from->personal) : null,
            recipients: $this->recipients($message, $headers),
            subject: $this->safeString(fn (): string => trim((string) $message->getSubject()?->toString())),
            textBody: $this->safeString(fn (): string => $message->hasTextBody() ? $message->getTextBody() : ''),
            htmlBody: $html,
            attachments: $this->attachments($message, $html),
        );
    }

    /**
     * @return list<string>
     */
    private function recipients(Message $message, RawHeaders $headers): array
    {
        $addresses = [];

        foreach ([$message->getTo(), $message->getCc()] as $attribute) {
            foreach ($attribute->toArray() as $address) {
                if ($address instanceof Address && is_string($address->mail) && $address->mail !== '') {
                    $addresses[] = $address->mail;
                }
            }
        }

        foreach (['delivered-to', 'x-original-to', 'envelope-to'] as $name) {
            foreach ($headers->all($name) as $value) {
                preg_match_all('/[^\s<>,;"]+@[^\s<>,;"]+/', $value, $matches);
                $addresses = [...$addresses, ...$matches[0]];
            }
        }

        return array_values(array_unique(array_map(static fn (string $address): string => strtolower(trim($address)), $addresses)));
    }

    /**
     * Files the sender attached. Inline images that only exist to draw the
     * HTML (signature logos) are left out.
     *
     * @return list<InboundAttachmentPart>
     */
    private function attachments(Message $message, string $html): array
    {
        $parts = [];

        foreach ($message->getAttachments() as $attachment) {
            if (! $attachment instanceof Attachment || $this->isInlineImage($attachment, $html)) {
                continue;
            }

            $content = (string) $attachment->getContent();
            $parts[] = new InboundAttachmentPart(
                name: $this->fileName($attachment),
                mimeType: strtolower((string) ($attachment->getMimeType() ?? $attachment->content_type ?? 'application/octet-stream')),
                content: $content,
            );
        }

        return $parts;
    }

    private function isInlineImage(Attachment $attachment, string $html): bool
    {
        $contentId = trim((string) $attachment->id, '<> ');

        return strtolower((string) $attachment->disposition) !== 'attachment'
            && str_starts_with(strtolower((string) $attachment->getMimeType()), 'image/')
            && $contentId !== ''
            && str_contains($html, 'cid:'.$contentId);
    }

    private function fileName(Attachment $attachment): string
    {
        $name = trim((string) ($attachment->getName() ?? $attachment->filename ?? ''));
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        return $name === '' || $name === '.' ? 'attachment' : mb_substr($name, 0, 200);
    }

    private function safeString(callable $read): string
    {
        try {
            return (string) $read();
        } catch (Throwable) {
            return '';
        }
    }
}
