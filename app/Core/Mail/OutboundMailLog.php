<?php

namespace App\Core\Mail;

use App\Core\Accounts\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata-only record of an outbound email (#359). Never holds subjects, bodies, links or tokens.
 */
#[Fillable([
    'template_key',
    'notification_type',
    'mailer',
    'user_id',
    'recipient_hash',
    'recipient_domain',
    'status',
    'created_at',
])]
class OutboundMailLog extends Model
{
    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public static function hashRecipient(string $address): string
    {
        return hash_hmac('sha256', strtolower(trim($address)), (string) config('app.key'));
    }
}
