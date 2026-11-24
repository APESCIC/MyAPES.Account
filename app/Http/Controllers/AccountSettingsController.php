<?php

namespace App\Http\Controllers;

use App\Services\AccountSettingsViewData;
use App\Services\AuthorizationProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AccountSettingsController extends Controller
{
    public function __construct(
        private readonly AccountSettingsViewData $viewData,
    ) {}

    public function show(Request $request): View
    {
        $user = $request->user();

        return view('account.show', [
            'activeAccountTab' => 'account',
            'user' => $user,
            ...$this->viewData->accountTab($user),
        ]);
    }

    public function profile(Request $request, AuthorizationProfile $authorization): View
    {
        $user = $request->user();

        return view('account.profile', [
            'activeAccountTab' => 'profile',
            'user' => $user,
            'usesStaffDirectoryProfile' => $authorization->hasDirectoryProtectedEligibility($user),
            ...$this->viewData->profileTab($user),
        ]);
    }

    public function security(Request $request): View
    {
        $user = $request->user();

        return view('account.security', [
            'activeAccountTab' => 'security',
            'user' => $user,
            ...$this->viewData->securityTab($user, $request),
        ]);
    }

    public function privacy(Request $request): View
    {
        return $this->page($request, 'privacy', 'account.privacy');
    }

    public function legacyProfileRedirect(): View
    {
        return view('account.legacy-profile-redirect');
    }

    private function page(Request $request, string $active, string $view): View
    {
        return view($view, [
            'activeAccountTab' => $active,
            'user' => $request->user(),
        ]);
    }
}
