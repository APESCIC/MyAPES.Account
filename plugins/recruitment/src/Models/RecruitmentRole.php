<?php

namespace Plugins\Recruitment\Models;

use App\Core\Accounts\User;
use Database\Factories\RecruitmentRoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'created_by',
    'title',
    'summary',
    'description',
    'category',
    'status',
    'location',
    'commitment',
    'published_at',
    'closed_at',
])]
class RecruitmentRole extends Model
{
    /** @use HasFactory<RecruitmentRoleFactory> */
    use HasFactory;

    protected static function newFactory(): RecruitmentRoleFactory
    {
        return RecruitmentRoleFactory::new();
    }

    public const CATEGORIES = ['staff', 'volunteer', 'student'];

    public const STATUSES = ['draft', 'open', 'closed'];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const PERMISSION_PREFIX = 'apes-cic.recruitment.';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(RecruitmentApplication::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can(self::PERMISSION_PREFIX.'view-all')) {
            return $query;
        }

        return $query->whereRaw('1 = 0');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }
}
