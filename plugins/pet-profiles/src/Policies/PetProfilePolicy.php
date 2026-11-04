<?php

namespace Plugins\PetProfiles\Policies;

use App\Core\Accounts\User;
use App\Services\ModuleState;
use Plugins\PetProfiles\Models\PetProfile;

/**
 * Pets are one shared pool across Shelter and Rescue and Pet Care Clinic
 * (#482, decision D4). Access is judged by the permissions of the module
 * the pet is opened in ($domain), whichever module first added it.
 */
class PetProfilePolicy
{
    public function __construct(
        private readonly ModuleState $modules,
    ) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, PetProfile $pet, ?string $domain = null): bool
    {
        $domain ??= $pet->service_domain;

        if ($domain === PetProfile::DOMAIN_SHELTER) {
            return $user->can('shelter-rescue.pet-profiles.view-all')
                || ($pet->user_id === $user->id
                    && $user->can('shelter-rescue.pet-profiles.view-own'));
        }

        if ($domain === PetProfile::DOMAIN_PETCARE) {
            return $this->modules->enabled('pet-care-clinic', 'pet-profiles')
                && ($user->can('pet-care-clinic.pet-profiles.view-all')
                    || ($pet->user_id === $user->id
                        && $user->can('pet-care-clinic.pet-profiles.view-own')));
        }

        return false;
    }

    public function update(User $user, PetProfile $pet, ?string $domain = null): bool
    {
        $domain ??= $pet->service_domain;

        if ($domain === PetProfile::DOMAIN_SHELTER) {
            return $user->can('shelter-rescue.pet-profiles.update-all')
                || ($pet->user_id === $user->id
                    && $user->can('shelter-rescue.pet-profiles.update-own'));
        }

        if ($domain === PetProfile::DOMAIN_PETCARE) {
            return $this->modules->enabled('pet-care-clinic', 'pet-profiles')
                && (($user->can('pet-care-clinic.pet-profiles.view-all')
                        && $user->can('pet-care-clinic.pet-profiles.update-all'))
                    || ($pet->user_id === $user->id
                        && $user->can('pet-care-clinic.pet-profiles.view-own')
                        && $user->can('pet-care-clinic.pet-profiles.update-own')));
        }

        return false;
    }

    public function createShelterCase(User $user, PetProfile $pet): bool
    {
        return $this->view($user, $pet, PetProfile::DOMAIN_SHELTER);
    }

    public function createConsultation(User $user, PetProfile $pet): bool
    {
        return $this->view($user, $pet, PetProfile::DOMAIN_PETCARE);
    }
}
