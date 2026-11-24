<?php

namespace App\Http\Controllers\Concerns;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\TotpTwoFactorService;
use App\Services\UserActivityTimeline;
use App\Services\UserProfileHubActions;
use App\Services\UserRecordsSummary;
use App\Support\DefaultJobRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
        $isOverview = $tab === 'overview';
        $isAccountManagement = $tab === 'account_management';
        $isRecords = $tab === 'records';
        $isActivity = $tab === 'activity';

        return [
            'hubTab' => $tab,
            'hubTabs' => self::HUB_TABS,
            'hubIsProfileOnly' => $managedUser->isProfileOnly(),
            'hubRecords' => $isRecords
                ? $records->forViewer(
                    $managedUser,
                    $viewer,
                    (int) $request->query('records_page', 1),
                )
                : null,
            'hubModulesInUse' => $isOverview
                ? $records->modulesInUse($managedUser, $viewer)
                : [],
            'hubActions' => $isOverview
                ? $actions->forClient($managedUser, $viewer)
                : [],
            'hubActivity' => $isActivity
                ? $timeline->paginate(
                    $managedUser,
                    (int) $request->query('activity_page', 1),
                )
                : null,
            'hubNotes' => $isOverview
                ? $managedUser->clientNotes()
                    ->with('author')
                    ->limit(50)
                    ->get()
                : collect(),
            'twoFactorEnabled' => ($isOverview || $isAccountManagement)
                && ($twoFactor->hasEnabledTwoFactor($managedUser)
                    || $twoFactor->hasPendingEnrolment($managedUser)),
            'hubLastSignInAt' => $isAccountManagement
                ? $this->hubLastSignInAt($managedUser)
                : null,
            'hubRoleLabels' => $isAccountManagement
                ? $this->hubRoleLabels($managedUser)
                : [],
            'hubCanManageAccess' => $this->hubViewerCanManageAccess($viewer, $managedUser),
        ];
    }

    protected function hubViewerCanManageAccess(User $viewer, User $managedUser): bool
    {
        if ($viewer->is($managedUser) || $viewer->suspended_at !== null) {
            return false;
        }

        if ($viewer->can('admin.users.manage')) {
            return true;
        }

        return $viewer->can(AuthorizationProfile::PERMISSION_CLIENT_MANAGE)
            && ! $managedUser->isProfileOnly()
            && ! app(AuthorizationProfile::class)->hasDirectoryProtectedEligibility($managedUser);
    }

    private function hubLastSignInAt(User $managedUser): ?Carbon
    {
        $at = AuditLog::query()
            ->where('auditable_type', $managedUser->getMorphClass())
            ->where('auditable_id', $managedUser->id)
            ->whereIn('event', [
                'auth.public_login_success',
                'auth.login_success',
                'auth.passkey_login_success',
                'auth.local_staff_login_success',
                'auth.signed_in',
            ])
            ->latest('id')
            ->value('created_at');

        return $at instanceof Carbon ? $at : (is_string($at) && $at !== '' ? Carbon::parse($at) : null);
    }

    /**
     * @return list<string>
     */
    private function hubRoleLabels(User $managedUser): array
    {
        $managedUser->loadMissing('roles');

        return $managedUser->roles
            ->map(static function ($role): string {
                $name = (string) $role->name;

                return DefaultJobRoles::isDefault($name)
                    ? DefaultJobRoles::title($name)
                    : $name;
            })
            ->filter()
            ->values()
            ->all();
    }
}
