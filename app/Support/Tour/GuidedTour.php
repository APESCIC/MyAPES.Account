<?php

namespace App\Support\Tour;

use App\Core\Accounts\User;
use App\Http\Controllers\HelpController;
use App\Services\AuthorizationProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Spike's guided tour (#489): one tour each for public accounts, staff roles
 * and administrators. It starts by itself on the dashboard until the user
 * finishes or skips it, and ?tour=1 restarts it. Each step lists targets in
 * order; the browser highlights the first one that is visible, so the same
 * step works with the desktop menu and the mobile drawer.
 */
final class GuidedTour
{
    public const AUDIENCE_PUBLIC = 'public';

    public const AUDIENCE_STAFF = 'staff';

    public const AUDIENCE_ADMIN = 'admin';

    public const AUDIENCES = [self::AUDIENCE_PUBLIC, self::AUDIENCE_STAFF, self::AUDIENCE_ADMIN];

    public const RESTART_QUERY = 'tour';

    private const MENU_BUTTON = '[data-workspace-menu-open]';

    private const BRAND = ['[data-workspace-rail] .sg-brand', '[data-workspace-topbar] .sg-brand'];

    private const SEARCH = ['[data-workspace-page-header] [data-workspace-palette-open]', '[data-workspace-topbar] [data-workspace-palette-open]'];

    private const FOOTER_HELP = '[data-tour-target="footer-help"]';

    public function __construct(private readonly AuthorizationProfile $profile) {}

    public function audienceFor(User $user): string
    {
        if (! $this->profile->hasDirectoryProtectedEligibility($user)) {
            return self::AUDIENCE_PUBLIC;
        }

        return Gate::forUser($user)->any(HelpController::ADMIN_GUIDE_ABILITIES)
            ? self::AUDIENCE_ADMIN
            : self::AUDIENCE_STAFF;
    }

    public function hasFinished(User $user, string $audience): bool
    {
        return isset(($user->guided_tours ?? [])[$audience]);
    }

    public function finish(User $user, string $audience, bool $skipped): void
    {
        $tours = $user->guided_tours ?? [];
        $tours[$audience] = ['at' => now()->toIso8601String(), 'skipped' => $skipped];

        $user->forceFill(['guided_tours' => $tours])->save();
    }

    /**
     * @return array{audience: string, autoStart: bool, restartParam: string, completeUrl: string, csrfToken: string, image: string, labels: array<string, string>, steps: list<array{targets: list<string>, title: string, body: string}>}
     */
    public function configFor(User $user, Request $request): array
    {
        $audience = $this->audienceFor($user);

        return [
            'audience' => $audience,
            'autoStart' => $request->query(self::RESTART_QUERY) !== null || ! $this->hasFinished($user, $audience),
            'restartParam' => self::RESTART_QUERY,
            'completeUrl' => route('tour.complete', $audience),
            'csrfToken' => $request->session()->token(),
            'image' => asset('mascot/spike-tip.png'),
            'labels' => [
                'next' => __('tour.buttons.next'),
                'previous' => __('tour.buttons.previous'),
                'done' => __('tour.buttons.done'),
                'progress' => __('tour.buttons.progress'),
                'close' => __('tour.buttons.close'),
            ],
            'steps' => $this->stepsFor($audience),
        ];
    }

    /**
     * @return list<array{targets: list<string>, title: string, body: string}>
     */
    public function stepsFor(string $audience): array
    {
        $welcome = $this->step(self::BRAND, __('tour.steps.welcome.title'), match ($audience) {
            self::AUDIENCE_ADMIN => __('tour.steps.welcome.admin'),
            self::AUDIENCE_STAFF => __('tour.steps.welcome.staff'),
            default => __('tour.steps.welcome.public'),
        });
        $menu = $this->step(['[data-workspace-rail] [data-workspace-nav]', self::MENU_BUTTON], __('tour.steps.menu.title'), $audience === self::AUDIENCE_PUBLIC
            ? __('tour.steps.menu.public')
            : __('tour.steps.menu.staff'));
        $search = $this->step(self::SEARCH, __('tour.steps.search.title'), __('tour.steps.search.body'));
        $account = $this->step(['[data-workspace-rail] .sg-dock__card', self::MENU_BUTTON], __('tour.steps.account.title'), __('tour.steps.account.body'));
        $help = $this->step([self::FOOTER_HELP], __('tour.steps.help.title'), __('tour.steps.help.body'));

        return match ($audience) {
            self::AUDIENCE_ADMIN => [
                $welcome,
                $this->step(['[data-workspace-rail] [data-workspace-panel-switch]', self::MENU_BUTTON], __('tour.steps.panel_switch.title'), __('tour.steps.panel_switch.body')),
                $menu,
                $this->step($this->navTarget('callers'), __('tour.steps.callers.title'), __('tour.steps.callers.body')),
                $search,
                $this->step($this->navTarget('help.staff'), __('tour.steps.guides.title'), __('tour.steps.guides.admin')),
                $account,
                $help,
            ],
            self::AUDIENCE_STAFF => [
                $welcome,
                $menu,
                $this->step($this->navTarget('callers'), __('tour.steps.callers.title'), __('tour.steps.callers.body')),
                $search,
                $this->step($this->navTarget('help.staff'), __('tour.steps.guides.title'), __('tour.steps.guides.staff')),
                $account,
                $help,
            ],
            default => [
                $welcome,
                $menu,
                $this->step($this->navTarget('profile'), __('tour.steps.profile.title'), __('tour.steps.profile.body')),
                $this->step($this->navTarget('bookings'), __('tour.steps.bookings.title'), __('tour.steps.bookings.body')),
                $search,
                $help,
            ],
        };
    }

    /**
     * @return list<string>
     */
    private function navTarget(string $key): array
    {
        return ['[data-workspace-rail] [data-nav-key="'.$key.'"]', self::MENU_BUTTON];
    }

    /**
     * @param  list<string>  $targets
     * @return array{targets: list<string>, title: string, body: string}
     */
    private function step(array $targets, string $title, string $body): array
    {
        return ['targets' => $targets, 'title' => $title, 'body' => $body];
    }
}
