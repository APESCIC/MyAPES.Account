<?php

namespace Plugins\Recruitment;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Signed-in accounts that may not use Recruitment at all (#345).
 */
final class RecruitmentAudience
{
    private const DENIED_PROTECTED_ROLES = [
        AuthorizationProfile::ROLE_STUDENT,
        AuthorizationProfile::ROLE_VOLUNTEER,
    ];

    public function __construct(
        private readonly AuthorizationProfile $profile,
    ) {}

    public function denies(?Authenticatable $user): bool
    {
        return $user instanceof User
            && in_array(
                $this->profile->effectiveProtectedRole($user),
                self::DENIED_PROTECTED_ROLES,
                true,
            );
    }
}
