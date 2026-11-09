<?php

namespace App\Core\Mail\Inbound;

/**
 * Reply-To tokens and subject tags for ticket and case email (#578).
 *
 * A token binds one record to one recipient: an HMAC of the record prefix,
 * record id and user id under the dedicated inbound key (never APP_KEY).
 */
final class ReplyToken
{
    private const SIGNATURE_LENGTH = 20;

    public function __construct(private readonly InboundMailSettings $settings) {}

    public function localTag(string $prefix, int $recordId, int $userId): string
    {
        return strtolower($prefix).$recordId.'.'.$this->signature($prefix, $recordId, $userId);
    }

    public function signature(string $prefix, int $recordId, int $userId): string
    {
        $payload = strtoupper($prefix).'|'.$recordId.'|'.$userId;

        return substr(hash_hmac('sha256', $payload, $this->settings->tokenKey()), 0, self::SIGNATURE_LENGTH);
    }

    public function verify(string $prefix, int $recordId, int $userId, string $signature): bool
    {
        return $this->settings->tokenKey() !== ''
            && hash_equals($this->signature($prefix, $recordId, $userId), strtolower($signature));
    }

    /**
     * Read a token from a recipient address such as communications+t12.abc@example.org.
     *
     * @return array{prefix: string, id: int, signature: string}|null
     */
    public function fromAddress(string $address): ?array
    {
        $mailbox = $this->settings->mailbox();

        if ($mailbox === null) {
            return null;
        }

        $pattern = '/^'.preg_quote($mailbox['local'], '/').'\+([a-z])(\d{1,18})\.([a-f0-9]{'.self::SIGNATURE_LENGTH.'})@'.preg_quote($mailbox['domain'], '/').'$/i';

        if (preg_match($pattern, trim($address), $matches) !== 1) {
            return null;
        }

        return ['prefix' => strtoupper($matches[1]), 'id' => (int) $matches[2], 'signature' => strtolower($matches[3])];
    }

    public static function subjectTag(string $prefix, int $recordId): string
    {
        return '[MYAPES-'.strtoupper($prefix).'-'.$recordId.']';
    }

    /**
     * @return array{prefix: string, id: int}|null
     */
    public static function fromSubject(string $subject): ?array
    {
        if (preg_match('/\[MYAPES-([A-Z])-(\d{1,18})\]/i', $subject, $matches) !== 1) {
            return null;
        }

        return ['prefix' => strtoupper($matches[1]), 'id' => (int) $matches[2]];
    }
}
