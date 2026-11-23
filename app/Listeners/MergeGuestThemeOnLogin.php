<?php

namespace App\Listeners;

use App\Core\Accounts\User;
use App\Support\Theme\ThemePreference;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * Applies the guest appearance cookie once when an account has no saved skin (#977).
 */
class MergeGuestThemeOnLogin
{
    public function __construct(
        private readonly ThemePreference $themePreference,
        private readonly Request $request,
    ) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->themePreference->mergeGuestCookieOnce(
            $user,
            $this->request->cookie(ThemePreference::GUEST_COOKIE),
        );
    }
}