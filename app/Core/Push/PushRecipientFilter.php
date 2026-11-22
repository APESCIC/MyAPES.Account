<?php

namespace App\Core\Push;

use App\Core\Accounts\User;

/**
 * Decides whether web push may be added for a recipient (#796).
 */
final class PushRecipientFilter
{
    public function __construct(
        private readonly PushNotificationTypeRegistry $types,
        private readonly PushRoleResolver $roles,
        private readonly PushPreferenceReader $preferences,
        private readonly PushQuietHoursEvaluator $quietHours,
        private readonly PushDivisionPreferenceReader $divisionPreferences,
    ) {}

    public function allows(User $user, PushRecipientContext $context): bool
    {
        if ($user->suspended_at !== null) {
            return false;
        }

        if ($context->actorUserId !== null && $context->actorUserId === $user->id) {
            return false;
        }

        if (! $user->pushSubscriptions()->exists()) {
            return false;
        }

        if ($this->quietHours->isQuietForUser($user)) {
            return false;
        }

        if (! $this->preferences->isTypeEnabled($user, $context->typeKey)) {
            return false;
        }

        $type = $this->types->get($context->typeKey);

        if ($type->divisionScoped) {
            $divisionKey = PushDivisionKey::fromPermission($context->divisionPermission);
            if ($divisionKey !== null && ! $this->divisionPreferences->isEnabled($user, $divisionKey)) {
                return false;
            }
        }
        $pushRole = $this->roles->forUser($user);

        if (! $type->availableForRole($pushRole)) {
            return false;
        }

        return $this->passesDivisionScope($user, $type, $context->divisionPermission);
    }

    private function passesDivisionScope(User $user, PushNotificationType $type, ?string $permission): bool
    {
        if (! $type->divisionScoped) {
            return true;
        }

        $pushRole = $this->roles->forUser($user);
        if (! in_array($pushRole, [PushRoleResolver::ROLE_VOLUNTEER, PushRoleResolver::ROLE_STUDENT], true)) {
            return true;
        }

        return $permission !== null && $permission !== '' && $user->can($permission);
    }
}
