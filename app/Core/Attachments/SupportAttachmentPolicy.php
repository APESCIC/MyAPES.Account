<?php

namespace App\Core\Attachments;

use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Who can upload, see, download and delete support attachments (#549).
 */
class SupportAttachmentPolicy
{
    public function __construct(
        private readonly AccountClassifier $accounts,
    ) {}

    /**
     * Staff who see every record upload as staff; clients upload to their own
     * records. Staff-side accounts never upload as a client (v0.54).
     */
    public function create(User $user, Model $attachable): bool
    {
        if (! $attachable instanceof HasSupportAttachments || ! Gate::forUser($user)->allows('view', $attachable)) {
            return false;
        }

        if ($user->can($attachable->attachmentPermissionPrefix().'view-all')) {
            return true;
        }

        return $attachable->attachmentOwnerId() === (int) $user->id
            && ! $this->accounts->isStaffSide($user);
    }

    public function view(User $user, SupportAttachment $attachment): bool
    {
        $attachable = $attachment->attachable;

        if (! $attachable instanceof HasSupportAttachments || ! Gate::forUser($user)->allows('view', $attachable)) {
            return false;
        }

        return ! $attachment->isInternal()
            || $user->can($attachable->attachmentPermissionPrefix().'view-all');
    }

    public function download(User $user, SupportAttachment $attachment): bool
    {
        return $this->view($user, $attachment);
    }

    public function delete(User $user, SupportAttachment $attachment): bool
    {
        return $this->view($user, $attachment)
            && ((int) $attachment->user_id === (int) $user->id
                || $user->can(AuthorizationProfile::PERMISSION_ADMIN_ACCESS));
    }
}
