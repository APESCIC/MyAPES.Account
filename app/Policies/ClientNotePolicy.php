<?php

namespace App\Policies;

use App\Core\Accounts\ClientNote;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;

class ClientNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AuthorizationProfile::PERMISSION_CLIENT_VIEW)
            || $user->can('admin.users.view');
    }

    public function create(User $user): bool
    {
        return $user->can(AuthorizationProfile::PERMISSION_CLIENT_VIEW)
            || $user->can('admin.users.manage');
    }

    public function update(User $user, ClientNote $note): bool
    {
        return $user->can('admin.users.manage');
    }

    public function delete(User $user, ClientNote $note): bool
    {
        return $user->can('admin.users.manage');
    }
}
