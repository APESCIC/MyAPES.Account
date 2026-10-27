<?php

namespace Plugins\Recruitment\Models;

use App\Core\Accounts\User;
use Database\Factories\RecruitmentApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable([
    'recruitment_role_id',
    'user_id',
    'status',
    'statement',
    'staff_notes',
    'submitted_at',
    'reviewed_at',
    'decided_at',
    'withdrawn_at',
])]
class RecruitmentApplication extends Model
{
    /** @use HasFactory<RecruitmentApplicationFactory> */
    use HasFactory;

    protected static function newFactory(): RecruitmentApplicationFactory
    {
        return RecruitmentApplicationFactory::new();
    }

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_SHORTLISTED = 'shortlisted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_SHORTLISTED,
        self::STATUS_REJECTED,
        self::STATUS_ACCEPTED,
        self::STATUS_WITHDRAWN,
        self::STATUS_CLOSED,
    ];

    /**
     * Allowed status transitions keyed by current status.
     *
     * @var array<string, list<string>>
     */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_SUBMITTED => [
            self::STATUS_UNDER_REVIEW,
            self::STATUS_WITHDRAWN,
        ],
        self::STATUS_UNDER_REVIEW => [
            self::STATUS_SHORTLISTED,
            self::STATUS_REJECTED,
            self::STATUS_ACCEPTED,
            self::STATUS_WITHDRAWN,
            self::STATUS_CLOSED,
        ],
        self::STATUS_SHORTLISTED => [
            self::STATUS_ACCEPTED,
            self::STATUS_REJECTED,
            self::STATUS_WITHDRAWN,
            self::STATUS_CLOSED,
        ],
        self::STATUS_REJECTED => [],
        self::STATUS_ACCEPTED => [],
        self::STATUS_WITHDRAWN => [],
        self::STATUS_CLOSED => [],
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_REJECTED,
        self::STATUS_ACCEPTED,
        self::STATUS_WITHDRAWN,
        self::STATUS_CLOSED,
    ];

    public const PERMISSION_PREFIX = 'apes-cic.recruitment.';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'decided_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function recruitmentRole(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRole::class, 'recruitment_role_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can(self::PERMISSION_PREFIX.'view-all')
            || $user->can(self::PERMISSION_PREFIX.'review-applications')) {
            return $query;
        }

        if ($user->can(self::PERMISSION_PREFIX.'view-own')) {
            return $query->where('user_id', $user->id);
        }

        return $query->whereRaw('1 = 0');
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeForRole(Builder $query, RecruitmentRole $role): Builder
    {
        return $query->where('recruitment_role_id', $role->id);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function canTransitionTo(string $status): bool
    {
        if (! in_array($status, self::STATUSES, true)) {
            return false;
        }

        return in_array($status, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function transitionTo(string $status): void
    {
        if (! $this->canTransitionTo($status)) {
            throw new InvalidArgumentException(
                "Cannot transition recruitment application from [{$this->status}] to [{$status}].",
            );
        }

        $attributes = ['status' => $status];

        if ($status === self::STATUS_UNDER_REVIEW && $this->reviewed_at === null) {
            $attributes['reviewed_at'] = now();
        }

        if (in_array($status, [self::STATUS_REJECTED, self::STATUS_ACCEPTED, self::STATUS_CLOSED], true)) {
            $attributes['decided_at'] = now();
        }

        if ($status === self::STATUS_WITHDRAWN) {
            $attributes['withdrawn_at'] = now();
        }

        $this->forceFill($attributes)->save();
    }

    /**
     * Create an application against an open role only.
     *
     * @param  array{statement?: string|null}  $attributes
     *
     * @throws InvalidArgumentException
     */
    public static function submitAgainstOpenRole(
        RecruitmentRole $role,
        User $user,
        array $attributes = [],
    ): self {
        if (! $role->isOpen()) {
            throw new InvalidArgumentException(
                'Applications may only be submitted against open recruitment roles.',
            );
        }

        return self::query()->create([
            'recruitment_role_id' => $role->id,
            'user_id' => $user->id,
            'status' => self::STATUS_SUBMITTED,
            'statement' => $attributes['statement'] ?? null,
            'submitted_at' => now(),
        ]);
    }
}
