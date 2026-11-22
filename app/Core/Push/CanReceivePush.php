<?php

namespace App\Core\Push;

use App\Core\Accounts\User;

/**
 * Callable helper around {@see PushRecipientFilter}.
 */
final class CanReceivePush
{
    public function __construct(
        private readonly PushRecipientFilter $filter,
    ) {}

    public function __invoke(User $user, PushRecipientContext $context): bool
    {
        return $this->filter->allows($user, $context);
    }
}