<?php

namespace Plugins\Recruitment\Policies;

use App\Core\Accounts\User;
use Plugins\Recruitment\Models\RecruitmentRole;
use App\Services\ModuleState;

class RecruitmentRolePolicy
{
    public function __construct(
        private readonly ModuleState $modules,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->moduleEnabled()
            && $user->can(RecruitmentRole::PERMISSION_PREFIX.'view-all');
    }

    public function view(User $user, RecruitmentRole $recruitmentRole): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->moduleEnabled()
            && $user->can(RecruitmentRole::PERMISSION_PREFIX.'create');
    }

    public function update(User $user, RecruitmentRole $recruitmentRole): bool
    {
        return $this->moduleEnabled()
            && $user->can(RecruitmentRole::PERMISSION_PREFIX.'update');
    }

    public function delete(User $user, RecruitmentRole $recruitmentRole): bool
    {
        return $this->moduleEnabled()
            && $user->can(RecruitmentRole::PERMISSION_PREFIX.'delete');
    }

    public function publish(User $user, RecruitmentRole $recruitmentRole): bool
    {
        return $this->update($user, $recruitmentRole)
            && $recruitmentRole->status !== RecruitmentRole::STATUS_OPEN;
    }

    public function close(User $user, RecruitmentRole $recruitmentRole): bool
    {
        return $this->update($user, $recruitmentRole)
            && $recruitmentRole->isOpen();
    }

    private function moduleEnabled(): bool
    {
        return $this->modules->enabled('apes-cic', 'recruitment');
    }
}
