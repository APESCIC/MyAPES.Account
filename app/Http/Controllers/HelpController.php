<?php

namespace App\Http\Controllers;

use App\Services\AuthorizationProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Public guide for everyone, a staff guide for staff, volunteers, students
 * and administrators (#464), and an admin guide for anyone who can open the
 * Admin area (#490). Section ids are stable so tour steps can link to them.
 */
class HelpController extends Controller
{
    /**
     * Any one of these opens part of the Admin area, so it also opens the admin guide.
     */
    public const ADMIN_GUIDE_ABILITIES = [
        'admin.analytics.view',
        'admin.users.view',
        'admin.groups.view',
        'admin.roles.view',
        'admin.permissions.view',
        'admin.modules.view',
        'admin.emails.view',
        'admin.settings.view',
        'admin.maintenance.manage',
    ];

    public function __construct(private readonly AuthorizationProfile $profile) {}

    public function public(Request $request): View
    {
        return view('legal.help', [
            'showStaffGuideLink' => $this->readsStaffGuide($request),
        ]);
    }

    public function staff(Request $request): View
    {
        abort_unless($this->readsStaffGuide($request), 403);

        return view('help.staff', [
            'showAdminGuideLink' => $this->readsAdminGuide($request),
        ]);
    }

    public function admin(Request $request): View
    {
        abort_unless($this->readsAdminGuide($request), 403);

        return view('help.admin');
    }

    private function readsStaffGuide(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && $this->profile->hasDirectoryProtectedEligibility($user);
    }

    private function readsAdminGuide(Request $request): bool
    {
        return $this->readsStaffGuide($request)
            && Gate::forUser($request->user())->any(self::ADMIN_GUIDE_ABILITIES);
    }
}
