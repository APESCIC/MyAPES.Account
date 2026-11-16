<?php

namespace App\Http\Controllers\Concerns;

use App\Core\Accounts\User;
use App\Services\TotpTwoFactorService;
use App\Services\UserActivityTimeline;
use App\Services\UserProfileHubActions;
use App\Services\UserRecordsSummary;
use Illuminate\Http\Request;

trait BuildsUserProfileHub
{
    /** @var list<string> */
    private const HUB_TABS = ['overview', 'details', 'access', 'records', 'activity'];

    /**
     * @return array<string, mixed>
     */
    protected function profileHubData(
        Request $request,
        User $managedUser,
        User $viewer,
        UserRecordsSummary $records,
        UserProfileHubActions $actions,
        UserActivityTimeline $timeline,
        TotpTwoFactorService $twoFactor,
    ): array {
        $tab = (string) $request->query('tab', 'overview');
        if (! in_array($tab, self::HUB_TABS, true)) {
            $tab = 'overview';
        }

        return [
            'hubTab' => $tab,
            'hubTabs' => self::HUB_TABS,
            'hubRecords' => $records->forViewer(
                $managedUser,
                $viewer,
                (int) $request->query('records_page', 1),
            ),
            'hubModulesInUse' => $records->modulesInUse($managedUser, $viewer),
            'hubActions' => $actions->forClient($managedUser, $viewer),
            'hubActivity' => $timeline->paginate(
                $managedUser,
                (int) $request->query('activity_page', 1),
            ),
            'hubNotes' => $managedUser->clientNotes()
                ->with('author')
                ->limit(50)
                ->get(),
            'twoFactorEnabled' => $twoFactor->hasEnabledTwoFactor($managedUser)
                || $twoFactor->hasPendingEnrolment($managedUser),
        ];
    }
}
