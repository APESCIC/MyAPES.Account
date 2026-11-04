<?php

namespace Plugins\Tickets\Models;

use App\Core\Accounts\User;
use App\Core\Attachments\SupportAttachment;
use App\Core\Content\SanitisedHtml;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'sub_core_key',
    'user_id',
    'assigned_to',
    'service_area',
    'sub_category',
    'affected_website_key',
    'subject',
    'priority',
    'status',
    'description',
    'closed_at',
])]
class SupportTicket extends Model
{
    public const SUB_CORE_APES_CIC = 'apes-cic';

    /** @var array<string, mixed> */
    protected $attributes = [
        'sub_core_key' => self::SUB_CORE_APES_CIC,
    ];

    public function scopeForSubCore(Builder $query, string $subCoreKey): Builder
    {
        return $query->where('sub_core_key', $subCoreKey);
    }

    public function scopeVisibleTo(
        Builder $query,
        User $user,
        string $subCoreKey = self::SUB_CORE_APES_CIC,
    ): Builder {
        $prefix = "{$subCoreKey}.tickets.";

        if ($user->can($prefix.'view-all')) {
            return $query;
        }

        if ($user->can($prefix.'view-own')) {
            return $query->where('user_id', $user->id);
        }

        return $query->whereRaw('1 = 0');
    }

    protected function casts(): array
    {
        return [
            'description' => SanitisedHtml::class,
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(SupportAttachment::class, 'attachable');
    }
}
