<?php

namespace Tests\Concerns;

use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\AuthorizationRoleMaterializer;
use App\Support\DefaultJobRoles;
use Spatie\Permission\PermissionRegistrar;

trait CreatesClinicalPersonas
{
    /**
     * Staff account holding the Vet job role (consultation create and edit).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function vetStaff(array $attributes = []): User
    {
        return $this->clinicalStaff(DefaultJobRoles::VET, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function vetNurseStaff(array $attributes = []): User
    {
        return $this->clinicalStaff(DefaultJobRoles::VET_NURSE, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function clinicalStaff(string $roleName, array $attributes): User
    {
        $clinician = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create($attributes);

        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();

        app(AuthorizationRoleMaterializer::class)->grant(
            $clinician,
            Role::query()
                ->where('guard_name', 'web')
                ->where('name', $roleName)
                ->firstOrFail(),
            RoleSource::SOURCE_LOCAL,
            actor: $actor,
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $clinician = $clinician->fresh();
        $clinician->unsetRelation('roles');
        $clinician->unsetRelation('permissions');

        return $clinician;
    }
}
