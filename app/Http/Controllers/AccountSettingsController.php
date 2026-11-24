<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AccountSettingsController extends Controller
{
    public function show(Request $request): View
    {
        return $this->page($request, 'account', 'account.show');
    }

    public function profile(Request $request): View
    {
        return $this->page($request, 'profile', 'account.profile');
    }

    public function security(Request $request): View
    {
        return $this->page($request, 'security', 'account.security');
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
