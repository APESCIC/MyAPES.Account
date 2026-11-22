<?php

namespace App\Support;

final class MascotTips
{
    /**
     * @return array{title: string, body: string}|null
     */
    public function forRoute(?string $routeName): ?array
    {
        if ($routeName === null
            || str_starts_with($routeName, 'admin.')
            || in_array($routeName, ['change-log.index', 'privacy', 'cookies', 'terms'], true)) {
            return null;
        }

        return match ($routeName) {
            'help' => [
                'title' => 'Look here first.',
                'body' => 'These notes cover public accounts, staff sign-in, and how to ask APES CIC for help.',
            ],
            'home' => [
                'title' => 'Pick the door that matches you.',
                'body' => 'Public Login is for clients. Staff and administrators should use Staff Login.',
            ],
            'recruitment.index' => [
                'title' => 'Browse open roles.',
                'body' => 'Filter by staff, volunteer, or student. Draft and closed roles stay private.',
            ],
            'recruitment.show' => [
                'title' => 'Read the full role.',
                'body' => 'Applications open in a later release. Use Back to return to the board.',
            ],
            'public.login' => [
                'title' => 'Use your public account.',
                'body' => 'Sign in with your username or email to reach your profile, pets, and support.',
            ],
            'public.register' => [
                'title' => 'Tick the services you need.',
                'body' => 'Choose at least one MyAPES service. You can update these later in Profile.',
            ],
            'password.request' => [
                'title' => 'Local public accounts only.',
                'body' => 'Use the email for your public account. Staff and Cloudron directory passwords stay on Cloudron.',
            ],
            'password.reset' => [
                'title' => 'Choose a new password.',
                'body' => 'After you save it, you will be signed in to your public account.',
            ],
            'staff.login' => [
                'title' => 'Staff use Cloudron.',
                'body' => 'APES staff and administrators sign in through APES Cloudron. Local QA can use seeded credentials.',
            ],
            'verification.notice' => [
                'title' => 'Check the signed email link.',
                'body' => 'Open the verification link before setup. You can send another copy if you cannot find the first.',
            ],
            'onboarding.edit' => [
                'title' => 'Finish the essentials.',
                'body' => 'Confirm your UK contact details and services so MyAPES can route the right tools to you.',
            ],
            'dashboard' => [
                'title' => 'Start with what needs you.',
                'body' => 'Open items appear on the right. Service totals sit below when plugins are available.',
            ],
            'apes-cic.index', 'shelter.index', 'petcare.index' => [
                'title' => 'Open a plugin card.',
                'body' => 'Enabled plugins for this Service appear here. If the list is empty, nothing is available to you yet.',
            ],
            'apes-cic.tickets.index', 'shelter.tickets.index', 'petcare.tickets.index' => [
                'title' => 'Describe the need clearly.',
                'body' => 'Choose a service area and priority, then write a subject staff can act on.',
            ],
            'apes-cic.cases.index' => [
                'title' => 'Cases are permission-scoped.',
                'body' => 'You only see cases available to you. Open one if you can create, or wait until a case is shared.',
            ],
            default => [
                'title' => 'Need a pointer?',
                'body' => 'Use the sidebar to move between Dashboard, Profile, and your services.',
            ],
        };
    }

    /**
     * @return array{title: string, body: string, route: string}|null
     */
    public function forCurrentRequest(): ?array
    {
        $routeName = request()->route()?->getName();
        $tip = $this->forRoute($routeName);

        if ($tip === null || $routeName === null) {
            return null;
        }

        return [
            ...$tip,
            'route' => $routeName,
        ];
    }
}
