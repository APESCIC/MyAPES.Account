<?php

namespace App\Core\Push;

/**
 * Inputs for deciding whether a user may receive a push (#796).
 */
final readonly class PushRecipientContext
{
    public function __construct(
        public string $typeKey,
        public ?int $actorUserId = null,
        public ?string $divisionPermission = null,
    ) {}
}