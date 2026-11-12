<?php

namespace App\Core\Mail\Inbound\Local;

/**
 * The ticket or case email a local test reply answers (#757).
 */
final class OriginalEmail
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $subject,
        public readonly string $replyTo,
        public readonly string $recipient,
        public readonly string $text = '',
    ) {}

    /**
     * From a Mailpit message (GET /api/v1/message/{ID}).
     *
     * @param  array<string, mixed>  $message
     */
    public static function fromMailpit(array $message): self
    {
        $first = static fn (mixed $list): string => is_array($list) && is_array($list[0] ?? null)
            ? strtolower(trim((string) ($list[0]['Address'] ?? '')))
            : '';
        $recipient = $first($message['To'] ?? []);

        return new self(
            messageId: trim((string) ($message['MessageID'] ?? ''), '<> '),
            subject: (string) ($message['Subject'] ?? ''),
            replyTo: $first($message['ReplyTo'] ?? []) ?: $first([$message['From'] ?? []]),
            recipient: $recipient,
            text: (string) ($message['Text'] ?? ''),
        );
    }
}
