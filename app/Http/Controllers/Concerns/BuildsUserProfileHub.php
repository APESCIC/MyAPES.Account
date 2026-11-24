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
    private const HUB_TABS = [
        'overview',
        'account_management',
        'account_profile',
        'records',
        'activity',
    ];

    /** @var array<string, string> */
    private const HUB_TAB_ALIASES = [
        'details' => 'account_profile',
        'access' => 'account_management',
    ];

    protected function resolveHubTab(Request $request): string
    {
        $tab = (string) $request->query('tab', 'overview');
        $tab = self::HUB_TAB_ALIASES[$tab] ?? $tab;

        if (! in_array($tab, self::HUB_TABS, true)) {
            return 'overview';
        }

        return $tab;
    }

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
        $tab = $this->resolveHubTab($request);

        return [
            'hubTab' => $tab,
            'hubTabs' => self::HUB_TABS,
            'hubIsProfileOnly' => $managedUser->isProfileOnly(),
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
