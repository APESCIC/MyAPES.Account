<?php

namespace Plugins\Cases\Policies;

use App\Core\Accounts\User;
use Plugins\Cases\Models\ShelterCase;
use App\Services\ModuleState;

class ShelterCasePolicy
{
    public function __construct(
        private readonly ModuleState $modules,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->can('shelter-rescue.cases.view-own')
            || $user->can('shelter-rescue.cases.view-all');
    }

    public function create(User $user): bool
    {
        return $user->can('shelter-rescue.cases.create')
            || $user->can('shelter-rescue.cases.create-for-client');
    }

    /**
     * Clients open cases for their own pets; staff only for someone else's (#511).
     */
    public function createFor(User $user, int $ownerId): bool
    {
        return $ownerId === $user->id
            ? $user->can('shelter-rescue.cases.create')
            : $user->can('shelter-rescue.cases.create-for-client');
    }

    public function view(User $user, ShelterCase $case): bool
    {
        $prefix = "{$case->sub_core_key}.cases.";

        return $this->modules->enabled($case->sub_core_key, 'cases')
            && ($user->can($prefix.'view-all')
            || ($case->user_id === $user->id
                && $user->can($prefix.'view-own')));
    }

    public function update(User $user, ShelterCase $case): bool
    {
        $prefix = "{$case->sub_core_key}.cases.";

        return $this->view($user, $case)
            && ($user->can($prefix.'update-all')
                || ($case->user_id === $user->id
                    && $user->can($prefix.'update-own')));
    }

    public function delete(User $user, ShelterCase $case): bool
    {
        return $this->view($user, $case)
            && $user->can("{$case->sub_core_key}.cases.delete");
    }
}
