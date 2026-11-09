<?php

namespace Plugins\Cases;

use App\Core\Accounts\User;
use Plugins\Cases\Models\CaseUpdate;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Cases\Notifications\ApesCicCaseUpdatedNotification;
use Plugins\Cases\Notifications\ShelterCaseUpdatedNotification;

/**
 * Emails staff and the case owner about a new update (#589). Shared by the
 * case pages and replies by email.
 */
final class CaseUpdateNotifier
{
    public function notify(ShelterCase $case, User $actor, string $visibility, int $attachmentCount = 0): void
    {
        $area = CasesArea::forModule((string) $case->sub_core_key);
        $recipients = User::query()
            ->eligibleStaff()
            ->withAuthorizationPermission($area->permission('view-all'))
            ->get();

        if ($visibility === CaseUpdate::VISIBILITY_PUBLIC
            && $case->user?->can('view', $case)) {
            $recipients->push($case->user);
        }

        if ($area->isShelter()
            && $visibility === CaseUpdate::VISIBILITY_INTERNAL) {
            $recipients = $recipients->reject(
                fn (User $recipient): bool => $recipient->id === $case->user_id,
            );
        }

        $recipients->unique('id')
            ->reject(fn (User $recipient): bool => $recipient->id === $actor->id)
            ->each(fn (User $recipient) => $recipient->notify(
                $area->isShelter()
                    ? new ShelterCaseUpdatedNotification($case, $actor, 'updated', $attachmentCount)
                    : new ApesCicCaseUpdatedNotification(
                        $case,
                        $actor,
                        'updated',
                        (string) $case->sub_core_key,
                        $area->showRouteName(),
                        $attachmentCount,
                    ),
            ));
    }
}
