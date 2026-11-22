<?php

namespace App\Core\Push;

use App\Core\Accounts\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata-only record of a web push delivery (#800). Never holds payloads, endpoints or keys.
 */
#[Fillable([
    'user_id',
    'subscription_hash',
    'notification_type',
    'result_code',
    'status',
    'attempts',
    'created_at',
])]
class PushDelivery extends Model
{
    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'result_code' => 'integer',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashSubscription(int $subscriptionId): string
    {
        return hash_hmac('sha256', (string) $subscriptionId, (string) config('app.key'));
    }
}