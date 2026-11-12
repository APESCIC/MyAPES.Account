<?php

namespace App\Core\Mail\Inbound\Diagnostics;

final class ImapResponse
{
    /**
     * @param  list<string>  $untagged
     */
    public function __construct(
        public readonly string $status,
        public readonly string $text,
        public readonly array $untagged = [],
    ) {}

    public function ok(): bool
    {
        return $this->status === 'OK';
    }
}
