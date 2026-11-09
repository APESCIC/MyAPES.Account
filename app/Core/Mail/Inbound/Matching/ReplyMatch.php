<?php

namespace App\Core\Mail\Inbound\Matching;

/**
 * Which record a reply is for, and how that was worked out (#580).
 */
final readonly class ReplyMatch
{
    public const BY_TOKEN = 'token';

    public const BY_SUBJECT = 'subject';

    public const BY_THREAD = 'thread';

    public function __construct(
        public string $prefix,
        public int $recordId,
        public string $method,
        public ?string $signature = null,
        public ?int $threadUserId = null,
    ) {}
}
