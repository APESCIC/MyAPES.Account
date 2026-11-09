<?php

namespace App\Core\Mail\Inbound;

use App\Core\Accounts\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One email received at the communications mailbox (#579).
 *
 * The raw message lives on the private disk until it is pruned (#586);
 * after that only metadata remains: status, reason, record and sender ids.
 */
#[Fillable([
    'message_id_hash',
    'message_id',
    'source',
    'from_address',
    'from_name',
    'from_hash',
    'from_domain',
    'subject',
    'raw_path',
    'raw_size',
    'status',
    'reason',
    'detail',
    'plugin',
    'module_slug',
    'record_prefix',
    'record_id',
    'user_id',
    'posted_type',
    'posted_id',
    'attachments_stored',
    'attachments_rejected',
    'spf',
    'dkim',
    'dmarc',
    'attempts',
    'notice_sent',
    'received_at',
    'processed_at',
    'raw_pruned_at',
])]
class InboundEmail extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_POSTED = 'posted';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_QUARANTINED = 'quarantined';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DISCARDED = 'discarded';

    public const STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_POSTED,
        self::STATUS_IGNORED,
        self::STATUS_QUARANTINED,
        self::STATUS_REJECTED,
        self::STATUS_FAILED,
        self::STATUS_DISCARDED,
    ];

    /** Statuses an administrator may retry or discard (#585). */
    public const ACTIONABLE_STATUSES = [
        self::STATUS_QUARANTINED,
        self::STATUS_FAILED,
    ];

    public const RAW_DISK = 'private';

    protected function casts(): array
    {
        return [
            'raw_size' => 'integer',
            'record_id' => 'integer',
            'attachments_stored' => 'integer',
            'attachments_rejected' => 'integer',
            'attempts' => 'integer',
            'notice_sent' => 'boolean',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'raw_pruned_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function posted(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function isActionable(): bool
    {
        return in_array($this->status, self::ACTIONABLE_STATUSES, true) && $this->hasRaw();
    }

    public function hasRaw(): bool
    {
        return is_string($this->raw_path) && $this->raw_path !== '' && $this->raw_pruned_at === null;
    }

    public function reasonLabel(): ?string
    {
        return $this->reason === null ? null : __('admin.emails.inbound.reasons.'.$this->reason);
    }

    public static function hashAddress(string $address): string
    {
        return hash_hmac('sha256', strtolower(trim($address)), (string) config('app.key'));
    }
}
