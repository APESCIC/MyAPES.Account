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
            ? strtolower(trim((string) ($list[0]['Address'] ?? ($list[0]['address'] ?? ''))))
            : '';
        $recipient = $first($message['To'] ?? []);

        return new self(
            messageId: trim((string) ($message['MessageID'] ?? $message['messageId'] ?? ''), '<> '),
            subject: (string) ($message['Subject'] ?? $message['subject'] ?? ''),
            replyTo: $first($message['ReplyTo'] ?? $message['replyTo'] ?? [])
                ?: $first([$message['From'] ?? $message['from'] ?? []]),
            recipient: $recipient,
            text: (string) ($message['Text'] ?? $message['text'] ?? ''),
        );
    }

    /**
     * From `herd mail:show --format=json` (#817).
     *
     * @param  array<string, mixed>  $message
     */
    public static function fromHerd(array $message): self
    {
        $first = static function (mixed $list): string {
            if (! is_array($list) || $list === []) {
                return '';
            }
            $entry = $list[0] ?? null;
            if (is_array($entry)) {
                return strtolower(trim((string) ($entry['address'] ?? $entry['Address'] ?? '')));
            }

            return strtolower(trim((string) $entry));
        };

        $headers = is_array($message['headers'] ?? null) ? $message['headers'] : [];
        $replyTo = $first($message['replyTo'] ?? []);
        if ($replyTo === '' && isset($headers['Reply-To'])) {
            $replyTo = strtolower(trim((string) (is_array($headers['Reply-To']) ? ($headers['Reply-To'][0] ?? '') : $headers['Reply-To'])));
            if (preg_match('/<([^>]+)>/', $replyTo, $m) === 1) {
                $replyTo = strtolower($m[1]);
            }
        }

        return new self(
            messageId: trim((string) ($message['messageId'] ?? ''), '<> '),
            subject: (string) ($message['subject'] ?? ''),
            replyTo: $replyTo !== '' ? $replyTo : $first($message['from'] ?? []),
            recipient: $first($message['to'] ?? []),
            text: (string) ($message['text'] ?? ''),
        );
    }
}
