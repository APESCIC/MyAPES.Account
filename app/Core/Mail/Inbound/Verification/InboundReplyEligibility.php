<?php

namespace App\Core\Mail\Inbound\Verification;

use App\Core\Accounts\User;
use App\Services\ModuleState;

/**
 * Who may reply to a record by email (#580): its owner, or eligible staff
 * who can see every record in the service. Replies are processed by a queue
 * worker with no sign-in session, so this checks roles and service choices
 * directly, the same way staff email recipients are chosen.
 */
final class InboundReplyEligibility
{
    public function __construct(private readonly ModuleState $modules) {}

    public function mayReply(User $user, string $subCoreKey, string $plugin, ?int $ownerId): bool
    {
        if ($user->suspended_at !== null || ! $this->modules->enabled($subCoreKey, $plugin)) {
            return false;
        }

        $prefix = "{$subCoreKey}.{$plugin}.";

        if ($this->isEligibleStaff($user, $prefix)) {
            return true;
        }

        return $ownerId === (int) $user->id
            && $this->hasRolePermission($user, $prefix.'view-own')
            && $this->hasRolePermission($user, $prefix.'comment-own')
            && $user->serviceSelections()->where('sub_core_key', $subCoreKey)->exists();
    }

    private function isEligibleStaff(User $user, string $prefix): bool
    {
        return User::query()
            ->whereKey($user->getKey())
            ->eligibleStaff()
            ->withAuthorizationPermission($prefix.'view-all')
            ->exists()
            && $this->hasRolePermission($user, $prefix.'comment-own');
    }

    private function hasRolePermission(User $user, string $permission): bool
    {
        return User::query()
            ->whereKey($user->getKey())
            ->withAuthorizationPermission($permission)
            ->exists();
    }
}
