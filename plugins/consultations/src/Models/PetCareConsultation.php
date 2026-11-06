<?php

namespace Plugins\Consultations\Models;

use App\Core\Accounts\ClientRecords\ArchivableClientRecord;
use App\Core\Accounts\User;
use App\Core\Content\SanitisedHtml;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Plugins\PetProfiles\Models\PetProfile;

#[Fillable([
    'pet_profile_id',
    'user_id',
    'assigned_to',
    'subject',
    'status',
    'notes',
    'scheduled_for',
    'closed_at',
])]
class PetCareConsultation extends Model
{
    use ArchivableClientRecord;

    public const PERMISSION_VIEW_OWN = 'pet-care-clinic.consultations.view-own';

    public const PERMISSION_CREATE = 'pet-care-clinic.consultations.create';

    public const PERMISSION_VIEW_ALL = 'pet-care-clinic.consultations.view-all';

    public const PERMISSION_UPDATE_ALL = 'pet-care-clinic.consultations.update-all';

    public const PERMISSION_ASSIGN = 'pet-care-clinic.consultations.assign';

    public const PERMISSION_CLOSE = 'pet-care-clinic.consultations.close';

    public function scopeForPetCareDomain(Builder $query): Builder
    {
        return $query->whereHas('petProfile');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can(self::PERMISSION_VIEW_ALL)) {
            return $query;
        }

        return $user->can(self::PERMISSION_VIEW_OWN)
            ? $query->where('user_id', $user->id)
            : $query->whereRaw('1 = 0');
    }

    protected function casts(): array
    {
        return [
            'notes' => SanitisedHtml::class,
            'scheduled_for' => 'datetime',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
