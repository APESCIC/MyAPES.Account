<?php

namespace App\Core\Mail\Inbound\Protection;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailSettings;

/**
 * Hourly limits on replies by email (#583): posts per sender, posts per
 * record, and at most one system notice per sender.
 */
final class InboundRateLimits
{
    public function __construct(private readonly InboundMailSettings $settings) {}

    public function senderOverLimit(User $user): bool
    {
        return InboundEmail::query()
            ->where('user_id', $user->id)
            ->where('status', InboundEmail::STATUS_POSTED)
            ->where('processed_at', '>=', now()->subHour())
            ->count() >= $this->settings->rateLimit('per_sender_per_hour');
    }

    public function recordOverLimit(string $prefix, int $recordId): bool
    {
        return InboundEmail::query()
            ->where('record_prefix', $prefix)
            ->where('record_id', $recordId)
            ->where('status', InboundEmail::STATUS_POSTED)
            ->where('processed_at', '>=', now()->subHour())
            ->count() >= $this->settings->rateLimit('per_record_per_hour');
    }

    public function mayNotify(User $user): bool
    {
        return InboundEmail::query()
            ->where('user_id', $user->id)
            ->where('notice_sent', true)
            ->where('processed_at', '>=', now()->subHour())
            ->count() < $this->settings->rateLimit('notices_per_sender_per_hour');
    }
}
