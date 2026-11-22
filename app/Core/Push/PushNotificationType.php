<?php

namespace App\Core\Push;

/**
 * One row of the event-to-role catalogue (#796).
 *
 * @phpstan-type RoleDefaults array<string, PushRoleDefault>
 */
final readonly class PushNotificationType
{
    /**
     * @param  RoleDefaults  $roleDefaults  Keys: staff, volunteer, student, service_user, admin
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public array $roleDefaults,
        public bool $securityLocked = false,
        public bool $divisionScoped = false,
    ) {}

    public function defaultForRole(?string $pushRole): PushRoleDefault
    {
        if ($pushRole === null) {
            return PushRoleDefault::NotApplicable;
        }

        return $this->roleDefaults[$pushRole] ?? PushRoleDefault::NotApplicable;
    }

    public function availableForRole(?string $pushRole): bool
    {
        return $this->defaultForRole($pushRole)->availableToRole();
    }
}