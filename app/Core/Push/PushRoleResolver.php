<?php

namespace App\Core\Push;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;

/**
 * Maps a signed-in person to the push catalogue role keys (#796).
 */
final class PushRoleResolver
{
    public const ROLE_STAFF = 'staff';

    public const ROLE_VOLUNTEER = 'volunteer';

    public const ROLE_STUDENT = 'student';

    public const ROLE_SERVICE_USER = 'service_user';

    public const ROLE_ADMIN = 'admin';

    /**
     * @return list<string>
     */
    public static function catalogueRoles(): array
    {
        return [
            self::ROLE_STAFF,
            self::ROLE_VOLUNTEER,
            self::ROLE_STUDENT,
            self::ROLE_SERVICE_USER,
            self::ROLE_ADMIN,
        ];
    }

  public function __construct(
    private readonly AuthorizationProfile $authorization,
  ) {}

  public function forUser(User $user): ?string
  {
    return match ($this->authorization->effectiveProtectedRole($user)) {
      AuthorizationProfile::ROLE_STAFF => self::ROLE_STAFF,
      AuthorizationProfile::ROLE_VOLUNTEER => self::ROLE_VOLUNTEER,
      AuthorizationProfile::ROLE_STUDENT => self::ROLE_STUDENT,
      AuthorizationProfile::ROLE_SERVICE_USER => self::ROLE_SERVICE_USER,
      AuthorizationProfile::ROLE_ADMINISTRATOR => self::ROLE_ADMIN,
      default => null,
    };
  }
}