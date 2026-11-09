<?php

namespace App\Core\Mail\Inbound\Parsing;

/**
 * A decoded inbound email (#579).
 */
final readonly class ParsedInboundEmail
{
    /**
     * @param  list<string>  $recipients  lower-case To, Cc and delivery addresses
     * @param  list<InboundAttachmentPart>  $attachments
     */
    public function __construct(
        public RawHeaders $headers,
        public ?string $messageId,
        public ?string $fromAddress,
        public ?string $fromName,
        public array $recipients,
        public string $subject,
        public string $textBody,
        public string $htmlBody,
        public array $attachments,
    ) {}

    public function fromDomain(): ?string
    {
        if ($this->fromAddress === null) {
            return null;
        }

        $at = strrpos($this->fromAddress, '@');

        return $at === false ? null : substr($this->fromAddress, $at + 1);
    }
}
