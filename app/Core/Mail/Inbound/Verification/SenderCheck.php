<?php

namespace App\Core\Mail\Inbound\Verification;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundEmail;

/**
 * The outcome of checking who sent a reply (#580).
 */
final readonly class SenderCheck
{
    private function __construct(
        public ?User $user,
        public ?string $status,
        public ?string $reason,
    ) {}

    public static function passed(User $user): self
    {
        return new self($user, null, null);
    }

    public static function quarantined(string $reason, ?User $user = null): self
    {
        return new self($user, InboundEmail::STATUS_QUARANTINED, $reason);
    }

    public static function rejected(string $reason, ?User $user = null): self
    {
        return new self($user, InboundEmail::STATUS_REJECTED, $reason);
    }

    public function passes(): bool
    {
        return $this->status === null && $this->user !== null;
    }
}
