<?php

namespace Plugins\PetProfiles\Services;

use App\Core\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Plugins\PetProfiles\Contracts\PetProfilesContract;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\PetProfiles\PetProfilesArea;

final class PetProfiles implements PetProfilesContract
{
    public function domainForModule(string $moduleSlug): string
    {
        return PetProfilesArea::forModule($moduleSlug)->serviceDomain;
    }

    public function visibleQuery(User $user, string $serviceDomain): Builder
    {
        return PetProfile::query()->visibleTo($user, $serviceDomain);
    }

    public function visibleOrdered(User $user, string $serviceDomain): Collection
    {
        return $this->visibleQuery($user, $serviceDomain)
            ->orderBy('name')
            ->get();
    }

    public function findVisibleOrFail(User $user, string $serviceDomain, int $id): PetProfile
    {
        return $this->visibleQuery($user, $serviceDomain)->findOrFail($id);
    }
}
