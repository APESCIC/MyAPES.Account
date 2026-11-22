<?php

namespace App\Core\Mail;

use App\Core\Accounts\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff-authored email campaign to account holders (#501–#509).
 */
class StaffMessage extends Model
{
    public const CATEGORY_SERVICE = 'service';

    public const CATEGORY_OPTIONAL = 'optional';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PREVIEW = 'preview';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'sender_id',
        'category',
        'subject',
        'body_html',
        'recipient_filter',
        'status',
        'recipient_count',
        'opted_out_count',
        'sent_count',
        'failed_count',
        'queued_at',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipient_filter' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PREVIEW], true);
    }

    public function isOptional(): bool
    {
        return $this->category === self::CATEGORY_OPTIONAL;
    }

    public function mailCategory(): MailCategory
    {
        return $this->isOptional() ? MailCategory::Optional : MailCategory::Service;
    }
}