<?php

namespace App\Support\Navigation;

use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
/**
 * Staff-bar Clients, Forms and Admin mega menus (#891, #892, #893).
 */
final class StaffTopMenuBuilder
{
    public function __construct(
        private readonly AccountClassifier $accounts,
        private readonly AuthorizationProfile $profile,
        private readonly NavigationPermissionCache $permissions,
    ) {}

    public function clientsMegaMenu(User $user): ?MegaMenuTopItem
    {
        if (! $this->accounts->hasStaffSideRole($user)) {
            return null;
        }

        $clientItems = [];

        if ($this->permissions->can($user, AuthorizationProfile::PERMISSION_CLIENT_SEARCH)) {
            $clientItems[] = new NavItem(
                'staff.clients.find',
                __('workspace.clients.find'),
                route('clients.index'),
                'search',
                ['clients.index', 'clients.show'],
                ['client', 'search', 'find', 'public'],
            );
        }

        if ($this->permissions->can($user, 'admin.users.view')) {
            $clientItems[] = new NavItem(
                'staff.clients.directory',
                __('workspace.clients.directory'),
                route('admin.public-accounts.index'),
                'users',
                ['admin.public-accounts.*'],
                ['client', 'directory', 'public', 'accounts'],
            );
        }

        if ($this->permissions->can($user, AuthorizationProfile::PERMISSION_CLIENT_CREATE)) {
            $clientItems[] = new NavItem(
                'staff.clients.add',
                __('workspace.clients.add'),
                route('clients.index').'#create',
                'user-plus',
                ['clients.index', 'clients.store'],
                ['client', 'create', 'add', 'register'],
            );
        }

        $clientItems[] = new NavItem(
            'staff.clients.callers',
            __('workspace.clients.find_caller'),
            route('callers.index'),
            'phone',
            ['callers.*'],
            ['caller', 'phone', 'verify', 'pin'],
        );

        $groups = [];
        if ($clientItems !== []) {
            $groups[] = new NavGroup('clients', __('terms.clients'), $clientItems);
        }

        $toolItems = [];
        if ($this->permissions->can($user, AuthorizationProfile::PERMISSION_MESSAGES_SEND)
            || $this->permissions->can($user, AuthorizationProfile::PERMISSION_MESSAGES_HISTORY_VIEW)) {
            $toolItems[] = new NavItem(
                'staff.clients.messages',
                __('staff_messages.nav'),
                route('messages.index'),
                'mail',
                ['messages.*'],
                ['message', 'email', 'mail', 'campaign'],
            );
        }

        if ($toolItems !== []) {
            $groups[] = new NavGroup('tools', __('workspace.clients.tools'), $toolItems);
        }

        if ($groups === []) {
            return null;
        }

        $patterns = [];
        foreach ($groups as $group) {
            foreach ($group->items as $item) {
                $patterns = [...$patterns, ...$item->activePatterns];
            }
        }

        $home = $clientItems[0]->url ?? $toolItems[0]->url;

        return new MegaMenuTopItem(
            'staff.clients',
            __('terms.clients'),
            'users',
            $home,
            $groups,
            array_values(array_unique($patterns)),
            __('workspace.clients.mega_description'),
        );
    }

    /**
     * Forms-only staff reach embed forms without a full Admin panel (#893 / #998).
     */
    public function formsMegaMenu(User $user, bool $hasAdminPanel): ?MegaMenuTopItem
    {
        if ($hasAdminPanel || ! $this->canManageForms($user)) {
            return null;
        }

        $item = new NavItem(
            'staff.forms',
            __('admin.nav.embed_forms'),
            route('admin.tickets.embed-forms.index'),
            'code-2',
            ['admin.tickets.embed-forms.*'],
            ['embed', 'forms', 'ticket', 'iframe'],
        );

        return new MegaMenuTopItem(
            'staff.forms',
            __('workspace.nav.forms'),
            'file-input',
            $item->url,
            [new NavGroup('forms', __('workspace.nav.forms'), [$item])],
            $item->activePatterns,
        );
    }

    public function staffAdminMegaMenu(User $user, NavPanel $adminPanel): ?MegaMenuTopItem
    {
        if ($adminPanel->groups === []) {
            return null;
        }

        $groups = array_values(array_filter(
            $adminPanel->groups,
            static fn (NavGroup $group): bool => $group->key !== 'support' && ! $group->isEmpty(),
        ));

        if ($groups === []) {
            return null;
        }

        $patterns = [];
        foreach ($groups as $group) {
            foreach ($group->items as $item) {
                $patterns = [...$patterns, ...$item->activePatterns];
            }
        }

        return new MegaMenuTopItem(
            'staff.admin',
            __('workspace.panels.admin'),
            'shield-check',
            $adminPanel->home()?->url ?? route('admin.index'),
            $groups,
            array_values(array_unique($patterns)),
        );
    }

    /**
     * @return list<MegaMenuTopItem>
     */
    public function adminPanelTopItems(NavPanel $adminPanel): array
    {
        $icons = [
            'overview' => 'layout-dashboard',
            'people' => 'users',
            'modules' => 'building-2',
            'system' => 'settings',
        ];

        $items = [];
        foreach (['overview', 'people', 'modules', 'system'] as $sectionKey) {
            $group = collect($adminPanel->groups)->first(
                static fn (NavGroup $group): bool => $group->key === $sectionKey && ! $group->isEmpty(),
            );
            if ($group === null) {
                continue;
            }

            $patterns = [];
            foreach ($group->items as $item) {
                $patterns = [...$patterns, ...$item->activePatterns];
            }

            $items[] = new MegaMenuTopItem(
                'admin.'.$sectionKey,
                $group->heading,
                $icons[$sectionKey] ?? 'circle',
                $group->items[0]->url,
                [$group],
                array_values(array_unique($patterns)),
            );
        }

        return $items;
    }

    public function formsUserMenuItem(User $user, bool $hasAdminPanel): ?NavItem
    {
        if ($hasAdminPanel || ! $this->canManageForms($user)) {
            return null;
        }

        return new NavItem(
            'user.forms',
            __('workspace.nav.forms'),
            route('admin.tickets.embed-forms.index'),
            'file-input',
            ['admin.tickets.embed-forms.*'],
            ['forms', 'embed', 'ticket'],
        );
    }

    private function canManageForms(User $user): bool
    {
        // Embed-form routes authorize admin.settings.manage today; forms.manage lands with #998.
        return $this->permissions->can($user, 'admin.settings.manage')
            && ! $this->profile->isAdministrator($user);
    }
}