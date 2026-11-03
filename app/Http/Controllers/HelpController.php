<?php

namespace App\Http\Controllers;

use App\Services\AuthorizationProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Public guide for everyone and a staff guide for staff, volunteers,
 * students and administrators (#464).
 */
class HelpController extends Controller
{
    public function __construct(private readonly AuthorizationProfile $profile) {}

    public function public(Request $request): View
    {
        return view('legal.help', [
            'showStaffGuideLink' => $this->isStaff($request),
        ]);
    }

    public function staff(Request $request): View
    {
        abort_unless($this->isStaff($request), 403);

        return view('help.staff');
    }

    private function isStaff(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && $this->profile->hasDirectoryProtectedEligibility($user);
    }
}
