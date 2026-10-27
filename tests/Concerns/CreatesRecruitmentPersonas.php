<?php

namespace Tests\Concerns;

use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\AuthorizationRoleMaterializer;
use App\Support\DefaultJobRoles;
use Spatie\Permission\PermissionRegistrar;

trait CreatesRecruitmentPersonas
{
    /**
     * Staff account holding the Human Resources job role (Recruitment manage).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function hrStaff(array $attributes = []): User
    {
        $hr = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create($attributes);

        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)
            ->create();

        app(AuthorizationRoleMaterializer::class)->grant(
            $hr,
            Role::query()
                ->where('guard_name', 'web')
                ->where('name', DefaultJobRoles::HUMAN_RESOURCES)
                ->firstOrFail(),
            RoleSource::SOURCE_LOCAL,
            actor: $actor,
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $hr = $hr->fresh();
        $hr->unsetRelation('roles');
        $hr->unsetRelation('permissions');

        return $hr;
    }
}
