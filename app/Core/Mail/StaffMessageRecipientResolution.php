<?php

namespace App\Core\Mail;

use App\Core\Accounts\User;
use Illuminate\Support\Collection;

/**
 * Resolved recipients for a staff message filter (#503).
 */
final readonly class StaffMessageRecipientResolution
{
    /**
     * @param  Collection<int, User>  $recipients  Users who will receive mail
     */
    public function __construct(
        public Collection $recipients,
        public int $optedOutCount,
    ) {}

    public function recipientCount(): int
    {
        return $this->recipients->count();
    }
}