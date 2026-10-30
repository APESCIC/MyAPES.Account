<?php

namespace App\Support\Navigation;

use App\Contracts\ModuleNavigationProvider;
use App\Contracts\ModuleRegistry;
use App\Core\Accounts\User;
use App\Core\Extensions\Navigation\PublicNavigationItem;
use App\Core\Extensions\Navigation\PublicNavigationRegistry;
use App\Core\Extensions\Navigation\StaffPluginNavigationItem;
use App\Core\Extensions\Navigation\StaffPluginNavigationRegistry;
use App\Core\Extensions\Plugins\PluginRegistry;
use App\Modules\ModuleNavigationItem;
use App\Modules\SubCoreNavigation;
use App\Services\AdminSearchCatalogue;
use App\Services\AuthorizationProfile;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Builds the workspace navigation (#384), replacing the permission logic that
 * used to live in the sidebar Blade. Hiding a link is not the security
 * boundary: every route keeps its own middleware.
 */
final class WorkspaceNavigation
{
    public function __construct(
        private readonly ModuleNavigationProvider $modules,
        private readonly ModuleRegistry $registry,
        private readonly StaffPluginNavigationRegistry $staffPlugins,
        private readonly PublicNavigationRegistry $publicPlugins,
        private readonly PluginRegistry $plugins,
        private readonly AdminSearchCatalogue $adminSearch,
        private readonly AuthorizationProfile $profile,
        private readonly Gate $gate,
    ) {}

    public function forRequest(?User $user, Request $request): WorkspaceMenu
    {
        $adminCatalogue = $user === null ? [] : $this->adminSearch->entriesFor($user);
        $moduleNavigation = $user === null ? [] : $this->modules->forUser($user);

        $panels = $user === null
            ? [NavPanel::PUBLIC => $this->publicPanel()]
            : [
                NavPanel::STAFF => $this->staffPanel($user, $moduleNavigation),
                NavPanel::ADMIN => $this->adminPanel($user, $adminCatalogue),
            ];

        $panels = array_filter($panels, static fn (NavPanel $panel): bool => $panel->groups !== []);

        [$currentPanelKey, $activeItemKey] = $this->resolveActive($panels, $request, $user);

        return new WorkspaceMenu(
            $panels,
            $currentPanelKey,
            $activeItemKey,
            $user === null ? [] : $this->quickActions($user, $moduleNavigation, $panels, $currentPanelKey),
            $this->settingsCommands($adminCatalogue),
            $user === null ? null : $this->dockUser($user),
        );
    }

    private function dockUser(User $user): DockUser
    {
        $initials = collect(preg_split('/\s+/u', trim($user->name)) ?: [])
            ->filter()
            ->take(2)
            ->map(static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        return new DockUser(
            $user->name,
            $initials,
            $this->profile->displayLabel($user),
            $this->profile->qaSelectorFor($user),
        );
    }

    /**
     * @param  list<SubCoreNavigation>  $moduleNavigation
     */
    private function staffPanel(User $user, array $moduleNavigation): NavPanel
    {
        $groups = [
            new NavGroup('overview', __('workspace.groups.overview'), [
                new NavItem('dashboard', __('public.chrome.app.blade.dashboard'), route('dashboard'), 'layout-dashboard', ['dashboard'], $this->words('workspace.keywords.dashboard')),
                new NavItem('profile', __('public.chrome.app.blade.profile'), route('profile.edit'), 'user-round', ['profile.*', 'two-factor.setup'], $this->words('workspace.keywords.profile')),
            ]),
            ...$this->moduleGroups($user, $moduleNavigation),
        ];

        $support = [
            new NavItem('help', __('public.chrome.app.blade.help'), route('help'), 'circle-help', ['help'], $this->words('workspace.keywords.help')),
            new NavItem('change-log', __('workspace.nav.change_log'), route('change-log.index'), 'scroll-text', ['change-log.*'], $this->words('workspace.keywords.change_log')),
        ];

        foreach ($this->publicPlugins->visibleTo($user) as $item) {
            $support[] = $this->publicItem($item);
        }

        $groups[] = new NavGroup('support', __('workspace.groups.support'), $support);

        $label = $this->isStaffAccount($user) ? __('workspace.panels.staff') : __('workspace.panels.account');

        return new NavPanel(NavPanel::STAFF, $label, $this->withoutEmptyGroups($groups));
    }

    /**
     * One group per enabled module, in Core > Modules order: the module home,
     * then its enabled plugins. Staff plugin destinations (Recruitment) join
     * the group of the module their routes live under.
     *
     * @param  list<SubCoreNavigation>  $moduleNavigation
     * @return list<NavGroup>
     */
    private function moduleGroups(User $user, array $moduleNavigation): array
    {
        /** @var array<string, array{key: string, heading: string, items: list<NavItem>}> $groups */
        $groups = [];

        foreach ($moduleNavigation as $navigation) {
            $groups[$this->routePrefix($navigation->subCore->routeName)] = [
                'key' => 'module.'.$navigation->subCore->key,
                'heading' => $navigation->subCore->name,
                'items' => $this->moduleItems($navigation),
            ];
        }

        foreach ($this->staffPlugins->enabledItems() as $staffItem) {
            $url = $staffItem->homeUrlFor($user);
            if ($url === null) {
                continue;
            }

            $prefix = $this->routePrefix($staffItem->routePatterns()[0] ?? '');
            $groups[$prefix] ??= $this->emptyModuleGroup($prefix);
            $groups[$prefix]['items'] = $this->mergeStaffItem($groups[$prefix]['items'], $staffItem, $url);
        }

        return array_values(array_map(
            static fn (array $group): NavGroup => new NavGroup($group['key'], $group['heading'], $group['items']),
            $groups,
        ));
    }

    /**
     * @return list<NavItem>
     */
    private function moduleItems(SubCoreNavigation $navigation): array
    {
        $subCore = $navigation->subCore;
        $prefix = $this->routePrefix($subCore->routeName);

        $items = [
            new NavItem(
                'module.'.$subCore->key.'.home',
                __('workspace.nav.service_home'),
                route($subCore->routeName),
                $subCore->icon,
                [$subCore->routeName, $prefix.'.*'],
                [$subCore->name, ...$this->words('workspace.keywords.service_home')],
            ),
        ];

        foreach ($navigation->modules as $module) {
            if (Route::has($module->routeName)) {
                $items[] = $this->pluginItem($module, $prefix);
            }
        }

        return $items;
    }

    private function pluginItem(ModuleNavigationItem $module, string $prefix): NavItem
    {
        $routeBase = str($module->routeName)->beforeLast('.')->toString();

        return new NavItem(
            'module.'.$module->subCoreKey.'.'.$module->moduleKey,
            $module->label,
            route($module->routeName),
            $module->icon,
            array_values(array_unique([
                $routeBase.'.*',
                $prefix.'.'.$module->moduleKey,
                $prefix.'.'.$module->moduleKey.'.*',
            ])),
            $this->pluginKeywords($module->moduleKey),
        );
    }

    /**
     * @param  list<NavItem>  $items
     * @return list<NavItem>
     */
    private function mergeStaffItem(array $items, StaffPluginNavigationItem $staffItem, string $url): array
    {
        $patterns = $staffItem->routePatterns();

        foreach ($items as $index => $item) {
            if (array_intersect($item->activePatterns, $patterns) !== []) {
                $items[$index] = $item->withDestination($url, $patterns);

                return $items;
            }
        }

        $items[] = new NavItem(
            'staff-plugin.'.str($staffItem->homeRouteName)->beforeLast('.')->replace('.', '-'),
            $staffItem->label,
            $url,
            $staffItem->icon,
            $patterns,
            $this->words('workspace.keywords.staff_plugin'),
        );

        return $items;
    }

    /**
     * @return array{key: string, heading: string, items: list<NavItem>}
     */
    private function emptyModuleGroup(string $prefix): array
    {
        foreach ($this->registry->subCores() as $subCore) {
            if ($this->routePrefix($subCore->routeName) === $prefix) {
                return ['key' => 'module.'.$subCore->key, 'heading' => $subCore->name, 'items' => []];
            }
        }

        return ['key' => 'module.'.$prefix, 'heading' => __('workspace.groups.services'), 'items' => []];
    }

    /**
     * @param  list<array{id: string, keywords: list<string>}>  $catalogue
     */
    private function adminPanel(User $user, array $catalogue): NavPanel
    {
        $keywords = [];
        foreach ($catalogue as $entry) {
            $keywords[$entry['id']] = [$entry['description'], ...$entry['keywords']];
        }

        $definitions = [
            'overview' => [
                ['admin.overview', __('admin.nav.overview'), route('admin.index'), 'layout-dashboard', ['admin.analytics.view'], ['admin.index']],
            ],
            'people' => [
                ['admin.users', __('terms.public_users'), route('admin.users.index', ['account_type' => 'public']), 'user-round', ['admin.users.view'], ['admin.users.*'], ['account_type' => 'public']],
                ['admin.staff', __('terms.staff'), route('admin.users.index', ['account_type' => 'staff']), 'badge-check', ['admin.users.view'], ['admin.users.*'], ['account_type' => 'staff']],
                ['admin.access', __('admin.nav.access'), route('admin.access.index'), 'shield-check', ['admin.groups.view', 'admin.roles.view', 'admin.permissions.view'], ['admin.access.*', 'admin.groups.*', 'admin.roles.*', 'admin.permissions.*']],
            ],
            'modules' => [
                ['admin.modules', __('admin.nav.modules'), route('admin.organisation-modules.index'), 'building-2', ['admin.modules.view'], ['admin.organisation-modules.*']],
                ['admin.plugins', __('admin.nav.plugins'), route('admin.modules.index'), 'puzzle', ['admin.modules.view'], ['admin.modules.*']],
            ],
            'system' => [
                ['admin.emails', __('admin.nav.emails'), route('admin.emails.index'), 'mail', ['admin.emails.view'], ['admin.emails.*']],
                ['admin.maintenance', __('admin.nav.maintenance'), route('admin.maintenance.index'), 'circle-pause', ['admin.maintenance.manage'], ['admin.maintenance.*']],
            ],
        ];

        $headings = [
            'overview' => __('workspace.groups.overview'),
            'people' => __('workspace.groups.people'),
            'modules' => __('workspace.groups.modules'),
            'system' => __('workspace.groups.system'),
        ];

        $groups = [];
        foreach ($definitions as $groupKey => $items) {
            $visible = [];
            foreach ($items as $definition) {
                [$key, $label, $url, $icon, $abilities, $patterns] = $definition;

                if (! $this->gate->forUser($user)->any($abilities)) {
                    continue;
                }

                $visible[] = new NavItem($key, $label, $url, $icon, $patterns, $keywords[$key] ?? [], $definition[6] ?? []);
            }

            $groups[] = new NavGroup($groupKey, $headings[$groupKey], $visible);
        }

        return new NavPanel(NavPanel::ADMIN, __('workspace.panels.admin'), $this->withoutEmptyGroups($groups));
    }

    private function publicPanel(): NavPanel
    {
        $explore = array_map(fn (PublicNavigationItem $item): NavItem => $this->publicItem($item), $this->publicPlugins->visibleTo(null));

        return new NavPanel(NavPanel::PUBLIC, __('workspace.panels.public'), $this->withoutEmptyGroups([
            new NavGroup('explore', __('workspace.groups.explore'), $explore),
            new NavGroup('account', __('workspace.groups.account'), [
                new NavItem('public.login', __('public.chrome.app.blade.public_login'), route('public.login'), 'log-in', ['public.login', 'password.*', 'two-factor.login'], $this->words('workspace.keywords.login')),
                new NavItem('public.register', __('public.chrome.app.blade.register'), route('public.register'), 'user-plus', ['public.register'], $this->words('workspace.keywords.register')),
                new NavItem('staff.login', __('public.chrome.app.blade.staff_login'), route('staff.login'), 'badge-check', ['staff.*'], $this->words('workspace.keywords.staff_login')),
            ]),
            new NavGroup('support', __('workspace.groups.support'), [
                new NavItem('help', __('public.chrome.app.blade.help'), route('help'), 'circle-help', ['help'], $this->words('workspace.keywords.help')),
                new NavItem('change-log', __('workspace.nav.change_log'), route('change-log.index'), 'scroll-text', ['change-log.*'], $this->words('workspace.keywords.change_log')),
            ]),
        ]));
    }

    private function publicItem(PublicNavigationItem $item): NavItem
    {
        return new NavItem(
            'public-plugin.'.str($item->routeName)->replace('.', '-'),
            $item->label,
            route($item->routeName),
            $item->icon,
            [$item->routeIsPattern],
            $this->pluginKeywords(str($item->routeIsPattern)->before('.')->toString()),
        );
    }

    /**
     * The most specific matching route pattern wins, so a plugin route beats
     * its module prefix. Pages never pass an active key.
     *
     * @param  array<string, NavPanel>  $panels
     * @return array{0: string, 1: ?string}
     */
    private function resolveActive(array $panels, Request $request, ?User $user): array
    {
        $route = $request->route();
        $routeName = $route?->getName();
        $best = null;

        if (is_string($routeName)) {
            $query = ['account_type' => $this->accountTypeFor($request)];

            foreach ($panels as $panelKey => $panel) {
                foreach ($panel->items() as $item) {
                    $score = $this->matchScore($item, $routeName, $query);
                    if ($score !== null && ($best === null || $score > $best[2])) {
                        $best = [$panelKey, $item->key, $score];
                    }
                }
            }
        }

        if ($best !== null) {
            return [$best[0], $best[1]];
        }

        $fallback = match (true) {
            $user === null => NavPanel::PUBLIC,
            is_string($routeName) && str_starts_with($routeName, 'admin.') && isset($panels[NavPanel::ADMIN]) => NavPanel::ADMIN,
            default => NavPanel::STAFF,
        };

        return [isset($panels[$fallback]) ? $fallback : (array_key_first($panels) ?? $fallback), null];
    }

    /**
     * @param  array<string, ?string>  $query
     */
    private function matchScore(NavItem $item, string $routeName, array $query): ?int
    {
        $bonus = 0;
        foreach ($item->activeQuery as $key => $value) {
            $current = $query[$key] ?? null;
            if ($current === null) {
                continue;
            }

            if ($current !== $value) {
                return null;
            }

            $bonus += 1000;
        }

        $best = null;
        foreach ($item->activePatterns as $pattern) {
            if (! str($routeName)->is($pattern)) {
                continue;
            }

            $score = strlen(rtrim($pattern, '*')) * 2 + (str_contains($pattern, '*') ? 0 : 1);
            $best = max($best ?? 0, $score);
        }

        return $best === null ? null : $best + $bonus;
    }

    private function accountTypeFor(Request $request): ?string
    {
        $type = $request->query('account_type');
        if ($type === 'public' || $type === 'staff') {
            return $type;
        }

        $subject = $request->route('user');

        return $subject instanceof User ? ($this->isStaffAccount($subject) ? 'staff' : 'public') : null;
    }

    /**
     * Same split as the Admin users list: a directory protected role makes a staff account.
     */
    private function isStaffAccount(User $user): bool
    {
        return in_array($this->profile->effectiveProtectedRole($user), $this->profile->directoryProtectedRoles(), true);
    }

    /**
     * @param  list<SubCoreNavigation>  $moduleNavigation
     * @param  array<string, NavPanel>  $panels
     * @return list<QuickAction>
     */
    private function quickActions(User $user, array $moduleNavigation, array $panels, string $currentPanelKey): array
    {
        $actions = [];

        foreach ($moduleNavigation as $navigation) {
            foreach ($navigation->modules as $module) {
                if (! Route::has($module->routeName) || ! $user->can($module->subCoreKey.'.'.$module->moduleKey.'.create')) {
                    continue;
                }

                $actions[] = new QuickAction(
                    'create.'.$module->subCoreKey.'.'.$module->moduleKey,
                    $this->createLabel($module),
                    $module->createUrl(),
                    'circle-plus',
                    $navigation->subCore->name,
                    ['new', 'add', 'create', $module->label, ...$this->pluginKeywords($module->moduleKey)],
                );
            }
        }

        if (isset($panels[NavPanel::STAFF], $panels[NavPanel::ADMIN])) {
            foreach ([NavPanel::STAFF, NavPanel::ADMIN] as $panelKey) {
                $home = $panels[$panelKey]->home();
                if ($panelKey === $currentPanelKey || $home === null) {
                    continue;
                }

                $actions[] = new QuickAction(
                    'switch.'.$panelKey,
                    __('workspace.quick.switch_panel', ['panel' => $panels[$panelKey]->label]),
                    $home->url,
                    $panelKey === NavPanel::ADMIN ? 'shield-check' : 'layout-dashboard',
                    '',
                    $this->words('workspace.keywords.switch_panel'),
                );
            }
        }

        $actions[] = new QuickAction('view-site', __('workspace.header.view_site'), route('home'), 'external-link', '', $this->words('workspace.keywords.view_site'));
        $actions[] = new QuickAction('account', __('workspace.quick.your_account'), route('profile.edit'), 'circle-user-round', '', $this->words('workspace.keywords.profile'));

        return $actions;
    }

    private function createLabel(ModuleNavigationItem $module): string
    {
        $key = 'workspace.quick.create.'.$module->moduleKey;
        $label = __($key);

        return $label === $key ? __('workspace.quick.create.default', ['item' => $module->label]) : $label;
    }

    /**
     * Plugin settings pages from the Admin search catalogue, so the palette
     * still finds them now the separate Admin search box is gone.
     *
     * @param  list<array{id: string, label: string, description: string, url: string, keywords: list<string>, group: string}>  $catalogue
     * @return list<array{id: string, label: string, section: string, url: string, icon: string, keywords: string}>
     */
    private function settingsCommands(array $catalogue): array
    {
        $commands = [];

        foreach ($catalogue as $entry) {
            if ($entry['group'] === 'admin') {
                continue;
            }

            $commands[] = [
                'id' => 'search:'.$entry['id'],
                'label' => $entry['label'],
                'section' => __('workspace.panels.admin').' · '.__('workspace.palette.settings_section'),
                'url' => $entry['url'],
                'icon' => $entry['group'] === 'plugin' ? 'puzzle' : 'settings',
                'keywords' => implode(' ', [$entry['description'], ...$entry['keywords']]),
            ];
        }

        return $commands;
    }

    /**
     * @param  list<NavGroup>  $groups
     * @return list<NavGroup>
     */
    private function withoutEmptyGroups(array $groups): array
    {
        return array_values(array_filter($groups, static fn (NavGroup $group): bool => ! $group->isEmpty()));
    }

    private function routePrefix(string $routeName): string
    {
        return str($routeName)->before('.')->toString();
    }

    /**
     * @return list<string>
     */
    private function pluginKeywords(string $slug): array
    {
        return $this->plugins->has($slug) ? $this->plugins->plugin($slug)->keywords() : [];
    }

    /**
     * @return list<string>
     */
    private function words(string $key): array
    {
        $value = __($key);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
