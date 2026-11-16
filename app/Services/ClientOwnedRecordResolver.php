<?php

namespace App\Services;

use App\Core\Accounts\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Resolve a public client owner for staff "create for this client" actions (#520).
 */
final class ClientOwnedRecordResolver
{
    public function __construct(
        private readonly AuthorizationProfile $profile,
    ) {}

    public function resolve(Request $request, User $actor): User
    {
        $forUserId = (int) $request->input('for_user', $request->query('for_user', 0));

        if ($forUserId <= 0 || $forUserId === (int) $actor->id) {
            return $actor;
        }

        $canAssign = $actor->can(AuthorizationProfile::PERMISSION_CLIENT_VIEW)
            || $actor->can('admin.users.manage')
            || $actor->can('admin.users.view');

        if (! $canAssign) {
            return $actor;
        }

        $owner = User::query()->find($forUserId);
        if ($owner === null || $this->profile->hasDirectoryProtectedEligibility($owner)) {
            throw ValidationException::withMessages([
                'for_user' => __('admin.users.hub.actions.invalid_client'),
            ]);
        }

        return $owner;
    }
}
