<?php

namespace Plugins\Recruitment\Policies;

use App\Core\Accounts\User;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Recruitment\Models\RecruitmentRole;
use App\Services\ModuleState;

class RecruitmentApplicationPolicy
{
    public function __construct(
        private readonly ModuleState $modules,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->moduleEnabled()
            && ($user->can(RecruitmentApplication::PERMISSION_PREFIX.'view-own')
                || $user->can(RecruitmentApplication::PERMISSION_PREFIX.'view-all')
                || $user->can(RecruitmentApplication::PERMISSION_PREFIX.'review-applications'));
    }

    public function manageAny(User $user): bool
    {
        return $this->moduleEnabled()
            && ($user->can(RecruitmentApplication::PERMISSION_PREFIX.'view-all')
                || $user->can(RecruitmentApplication::PERMISSION_PREFIX.'review-applications'));
    }

    public function viewOwn(User $user, RecruitmentApplication $recruitmentApplication): bool
    {
        return $this->moduleEnabled()
            && $user->can(RecruitmentApplication::PERMISSION_PREFIX.'view-own')
            && $recruitmentApplication->user_id === $user->id;
    }

    public function view(User $user, RecruitmentApplication $recruitmentApplication): bool
    {
        if (! $this->moduleEnabled()) {
            return false;
        }

        if ($user->can(RecruitmentApplication::PERMISSION_PREFIX.'view-all')
            || $user->can(RecruitmentApplication::PERMISSION_PREFIX.'review-applications')) {
            return true;
        }

        return $user->can(RecruitmentApplication::PERMISSION_PREFIX.'view-own')
            && $recruitmentApplication->user_id === $user->id;
    }

    public function create(User $user, ?RecruitmentRole $role = null): bool
    {
        if (! $this->moduleEnabled()
            || ! $user->can(RecruitmentApplication::PERMISSION_PREFIX.'view-own')) {
            return false;
        }

        if ($role !== null && ! $role->isOpen()) {
            return false;
        }

        return true;
    }

    public function update(User $user, RecruitmentApplication $recruitmentApplication): bool
    {
        return $this->review($user, $recruitmentApplication);
    }

    public function review(User $user, RecruitmentApplication $recruitmentApplication): bool
    {
        return $this->moduleEnabled()
            && $user->can(RecruitmentApplication::PERMISSION_PREFIX.'review-applications')
            && ! $recruitmentApplication->isTerminal();
    }

    public function withdraw(User $user, RecruitmentApplication $recruitmentApplication): bool
    {
        return $this->moduleEnabled()
            && $user->can(RecruitmentApplication::PERMISSION_PREFIX.'view-own')
            && $recruitmentApplication->user_id === $user->id
            && $recruitmentApplication->canTransitionTo(RecruitmentApplication::STATUS_WITHDRAWN);
    }

    public function delete(User $user, RecruitmentApplication $recruitmentApplication): bool
    {
        return $this->moduleEnabled()
            && $user->can(RecruitmentApplication::PERMISSION_PREFIX.'delete');
    }

    private function moduleEnabled(): bool
    {
        return $this->modules->enabled('apes-cic', 'recruitment');
    }
}
