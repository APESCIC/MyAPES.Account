<?php

namespace App\Core\Mail\Inbound\Parsing;

/**
 * One file attached to an inbound email (#584).
 */
final readonly class InboundAttachmentPart
{
    public function __construct(
        public string $name,
        public string $mimeType,
        public string $content,
    ) {}

    public function size(): int
    {
        return strlen($this->content);
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->name, PATHINFO_EXTENSION));
    }
}
