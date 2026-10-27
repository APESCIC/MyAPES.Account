<?php

namespace Plugins\Recruitment\Navigation;

use App\Services\ModuleSettingsService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Plugins\Recruitment\RecruitmentAbilities;
use Plugins\Recruitment\RecruitmentAudience;

/**
 * Resolves where the single signed-in Recruitment primary lands (#346).
 */
final class RecruitmentPrimaryNav
{
    public function __construct(
        private readonly RecruitmentAudience $audience,
        private readonly ModuleSettingsService $moduleSettings,
    ) {}

    public function destinationFor(Authenticatable $user): ?string
    {
        if ($this->audience->denies($user)) {
            return null;
        }

        $gate = Gate::forUser($user);

        if ($gate->allows(RecruitmentAbilities::VIEW_ALL)) {
            return route('apes-cic.recruitment.index');
        }

        if ($gate->allows(RecruitmentAbilities::REVIEW_APPLICATIONS)) {
            return route('apes-cic.recruitment.applications.index');
        }

        if ($this->moduleSettings->recruitmentPublicBoardEnabled()) {
            return route('recruitment.index');
        }

        return null;
    }
}
