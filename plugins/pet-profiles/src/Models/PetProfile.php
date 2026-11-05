<?php

namespace Plugins\PetProfiles\Models;

use App\Core\Accounts\ClientRecords\ArchivableClientRecord;
use App\Core\Accounts\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'service_domain',
    'name',
    'species',
    'age_years',
    'sex',
    'neutering_status',
    'health_issues',
    'photo_path',
])]
class PetProfile extends Model
{
    use ArchivableClientRecord;

    public const DOMAIN_SHELTER = 'shelter';

    public const DOMAIN_PETCARE = 'petcare';

    public function scopeVisibleTo(
        Builder $query,
        User $user,
        ?string $serviceDomain = null,
    ): Builder {
        $serviceDomain ??= self::DOMAIN_PETCARE;

        if ($serviceDomain === self::DOMAIN_SHELTER) {
            if ($user->can('shelter-rescue.pet-profiles.view-all')) {
                return $query;
            }

            return $user->can('shelter-rescue.pet-profiles.view-own')
                ? $query->where('user_id', $user->id)
                : $query->whereRaw('1 = 0');
        }

        if ($serviceDomain === self::DOMAIN_PETCARE) {
            if ($user->can('pet-care-clinic.pet-profiles.view-all')) {
                return $query;
            }

            return $user->can('pet-care-clinic.pet-profiles.view-own')
                ? $query->where('user_id', $user->id)
                : $query->whereRaw('1 = 0');
        }

        return $query->whereRaw('1 = 0');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
