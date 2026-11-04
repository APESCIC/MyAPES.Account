<?php

namespace Plugins\Cases\Models;

use App\Core\Accounts\User;
use App\Core\Attachments\SupportAttachment;
use App\Core\Content\SanitisedHtml;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Plugins\PetProfiles\Models\PetProfile;

#[Fillable([
    'sub_core_key',
    'pet_profile_id',
    'user_id',
    'assigned_to',
    'case_type',
    'category',
    'sub_category',
    'affected_website_key',
    'priority',
    'status',
    'title',
    'details',
    'opened_at',
    'resolved_at',
    'closed_at',
])]
class ShelterCase extends Model
{
    public const SUB_CORE_APES_CIC = 'apes-cic';

    public const SUB_CORE_SHELTER_RESCUE = 'shelter-rescue';

    /** @var array<string, mixed> */
    protected $attributes = [
        'sub_core_key' => self::SUB_CORE_SHELTER_RESCUE,
    ];

    public function scopeForSubCore(Builder $query, string $subCoreKey): Builder
    {
        return $query->where('sub_core_key', $subCoreKey);
    }

    public function scopeVisibleTo(
        Builder $query,
        User $user,
        string $subCoreKey = self::SUB_CORE_SHELTER_RESCUE,
    ): Builder {
        $prefix = "{$subCoreKey}.cases.";

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
            'details' => SanitisedHtml::class,
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function petProfile(): BelongsTo
    {
        return $this->belongsTo(PetProfile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(CaseUpdate::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(SupportAttachment::class, 'attachable');
    }
}
