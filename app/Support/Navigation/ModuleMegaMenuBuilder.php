<?php

namespace App\Support\Navigation;

use App\Contracts\ModuleRegistry;
use App\Core\Access\GroupAccessMatrix;
use App\Core\Accounts\User;
use App\Core\Extensions\Modules\ModulePackageRegistry;
use App\Core\Extensions\Plugins\PluginManifest;
use App\Core\Extensions\Plugins\PluginMenuAction;
use App\Core\Extensions\Plugins\PluginRegistry;
use App\Modules\SubCoreDefinition;
use App\Modules\SubCoreNavigation;
use App\Services\ModuleCatalogueProjection;
use App\Services\ServiceEntitlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Builds one top mega-menu item per enabled module from the registry (#885).
 *
 * Plugin {@see PluginManifest::menuActionsFor} supply the columns; when a
 * plugin leaves them empty the builder derives staff view/create actions from
 * legacy {@see PluginManifest::$navigation} entries.
 */
final class ModuleMegaMenuBuilder
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly PluginRegistry $plugins,
        private readonly ModulePackageRegistry $modulePackages,
        private readonly ModuleCatalogueProjection $projection,
        private readonly NavigationPermissionCache $permissions,
        private readonly ServiceEntitlement $entitlement,
        private readonly GroupAccessMatrix $groupAccess,
        private readonly Request $request,
    ) {}

    /**
     * @param  list<SubCoreNavigation>  $moduleNavigation
     * @return list<MegaMenuTopItem>
     */
    public function forAudience(User $user, array $moduleNavigation, string $audience): array
    {
        $enabled = array_flip($this->projection->enabledInstanceKeys());
        $items = [];
        $seenKeys = [];

        foreach ($moduleNavigation as $navigation) {
            $subCore = $navigation->subCore;
            if (! $this->modulePackages->isEnabled($subCore->key)) {
                continue;
            }

            $top = $this->buildModuleItem($user, $subCore, $audience, $enabled);
            if ($top === null || $top->isEmpty()) {
                continue;
            }

            $items[] = $top;
            $seenKeys[$subCore->key] = true;
        }

        // Enabled modules with no legacy nav entries still appear when they
        // declare menu actions (so a newly enabled module needs no menu code).
        foreach ($this->registry->subCores() as $subCore) {
            if (isset($seenKeys[$subCore->key]) || ! $this->modulePackages->isEnabled($subCore->key)) {
                continue;
            }

            $top = $this->buildModuleItem($user, $subCore, $audience, $enabled);
            if ($top === null || $top->isEmpty()) {
                continue;
            }

            $items[] = $top;
        }

        usort(
            $items,
            function (MegaMenuTopItem $left, MegaMenuTopItem $right): int {
                $leftOrder = $this->registry->subCore(str_replace('module.', '', $left->key))->navigationOrder;
                $rightOrder = $this->registry->subCore(str_replace('module.', '', $right->key))->navigationOrder;

                return $leftOrder <=> $rightOrder;
            },
        );

        return $items;
    }

    /**
     * @param  array<string, int>  $enabled
     */
    private function buildModuleItem(
        User $user,
        SubCoreDefinition $subCore,
        string $audience,
        array $enabled,
    ): ?MegaMenuTopItem {
        if (! Route::has($subCore->routeName)) {
            return null;
        }

        if (! $this->entitlement->allows($user, $subCore->key, $this->request)) {
            return null;
        }

        $prefix = str($subCore->routeName)->before('.')->toString();
        $overview = new NavItem(
            'module.'.$subCore->key.'.overview',
            __('workspace.nav.overview'),
            route($subCore->routeName),
            $subCore->icon,
            [$subCore->routeName, $prefix.'.*'],
            [$subCore->name, 'overview', 'home'],
            description: __('workspace.nav.overview_description', ['module' => $subCore->name]),
        );

        $groups = [
            new NavGroup('overview', __('workspace.groups.overview'), [$overview]),
        ];

        $pluginOrder = [];
        foreach ($this->registry->shippedInstances() as $instance) {
            if ($instance->subCore->key !== $subCore->key || ! isset($enabled[$instance->key()])) {
                continue;
            }

            $plugin = $this->plugins->plugin($instance->module->key);
            $actions = $this->visibleActions($user, $plugin, $subCore->key, $audience, $instance->key());
            if ($audience === PluginMenuAction::AUDIENCE_STAFF) {
                $actions = $this->mergeMenuActions(
                    $actions,
                    $this->visibleActions($user, $plugin, $subCore->key, PluginMenuAction::AUDIENCE_PUBLIC, $instance->key()),
                );
            }
            if ($actions === []) {
                continue;
            }

            $pluginOrder[$plugin->slug] = min(array_map(
                static fn (PluginMenuAction $action): int => $action->order,
                $actions,
            ));

            $items = [];
            $seen = [];
            foreach ($actions as $action) {
                $key = 'module.'.$subCore->key.'.'.$plugin->slug.'.'.$action->order;
                if (isset($seen[$action->routeName.'|'.($action->anchor ?? '')])) {
                    continue;
                }
                $seen[$action->routeName.'|'.($action->anchor ?? '')] = true;

                if (! Route::has($action->routeName)) {
                    continue;
                }

                $routeBase = str($action->routeName)->beforeLast('.')->toString();
                $label = $this->actionLabel($action, $plugin);

                $items[] = new NavItem(
                    $key,
                    $label,
                    $action->url(),
                    $action->icon,
                    array_values(array_unique([
                        $routeBase.'.*',
                        $prefix.'.'.$plugin->slug,
                        $prefix.'.'.$plugin->slug.'.*',
                    ])),
                    [$plugin->label(), $subCore->name, $label],
                    description: $this->actionDescription($action),
                );
            }

            if ($items === []) {
                continue;
            }

            $groups[] = new NavGroup('plugin.'.$plugin->slug, $plugin->label(), $items);
        }

        // Keep plugin columns in manifest order (lowest action order first).
        $overviewGroup = array_shift($groups);
        usort(
            $groups,
            static function (NavGroup $left, NavGroup $right) use ($pluginOrder): int {
                $leftSlug = str_replace('plugin.', '', $left->key);
                $rightSlug = str_replace('plugin.', '', $right->key);

                return ($pluginOrder[$leftSlug] ?? 0) <=> ($pluginOrder[$rightSlug] ?? 0);
            },
        );
        array_unshift($groups, $overviewGroup);

        if ($audience === PluginMenuAction::AUDIENCE_PUBLIC) {
            $booking = $this->publicBookingGroup($subCore->key);
            if ($booking !== null) {
                $groups[] = $booking;
            }
        }

        $shortKey = 'workspace.nav.short.'.$subCore->key;
        $shortLabel = __($shortKey);
        $triggerLabel = $shortLabel !== $shortKey ? $shortLabel : $subCore->name;

        $top = new MegaMenuTopItem(
            'module.'.$subCore->key,
            $triggerLabel,
            $subCore->icon,
            route($subCore->routeName),
            $groups,
            [$subCore->routeName, $prefix.'.*'],
            $subCore->description,
            $subCore->name,
        );

        return $top->isEmpty() ? null : $top;
    }

    /**
     * @return list<PluginMenuAction>
     */
    private function visibleActions(
        User $user,
        PluginManifest $plugin,
        string $moduleSlug,
        string $audience,
        string $instanceKey,
    ): array {
        $actions = $plugin->menuActionsFor($moduleSlug, $audience);
        $visible = [];

        foreach ($actions as $action) {
            if (! $this->canUse($user, $instanceKey, $action->requiredAbility)) {
                continue;
            }

            $visible[] = $action;
        }

        return $visible;
    }

    private function canUse(User $user, string $instanceKey, ?string $requiredAbility): bool
    {
        [$subCore, $module] = explode(':', $instanceKey, 2);

        if ($this->groupAccess->denies($user, $subCore, $module)) {
            return false;
        }

        if ($requiredAbility !== null) {
            return $this->permissions->can($user, "{$subCore}.{$module}.{$requiredAbility}");
        }

        return $this->permissions->can($user, "{$subCore}.{$module}.view-all")
            || $this->permissions->can($user, "{$subCore}.{$module}.view-own");
    }

    private function publicBookingGroup(string $moduleSlug): ?NavGroup
    {
        $page = match ($moduleSlug) {
            'apes-cic' => 'apes',
            'shelter-rescue' => 'shelter-and-rescue',
            'pet-care-clinic' => 'pet-care-clinic',
            default => null,
        };

        if ($page === null || ! Route::has('bookings.show')) {
            return null;
        }

        return new NavGroup('bookings', __('bookings.nav'), [
            new NavItem(
                'module.'.$moduleSlug.'.bookings',
                __('workspace.mega.book_appointment'),
                route('bookings.show', $page),
                'calendar',
                ['bookings.show', 'bookings.*'],
                $this->bookingKeywords($page),
            ),
        ]);
    }

    /**
     * @return list<string>
     */
    private function bookingKeywords(string $page): array
    {
        $keywords = __('bookings.keywords');

        return is_array($keywords) ? array_values(array_filter($keywords, 'is_string')) : [];
    }

    /**
     * @param  list<PluginMenuAction>  $primary
     * @param  list<PluginMenuAction>  $secondary
     * @return list<PluginMenuAction>
     */
    private function mergeMenuActions(array $primary, array $secondary): array
    {
        if ($secondary === []) {
            return $primary;
        }

        $merged = $primary;
        $seen = [];
        foreach ($merged as $action) {
            $seen[$action->routeName.'|'.($action->anchor ?? '')] = true;
        }

        foreach ($secondary as $action) {
            $key = $action->routeName.'|'.($action->anchor ?? '');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $merged[] = $action;
        }

        usort($merged, static fn (PluginMenuAction $a, PluginMenuAction $b): int => $a->order <=> $b->order);

        return $merged;
    }

    private function actionLabel(PluginMenuAction $action, PluginManifest $plugin): string
    {
        if ($action->labelKey !== null && $action->labelKey !== '') {
            $translated = __($action->labelKey);
            if ($translated !== $action->labelKey) {
                return $translated;
            }
        }

        if ($action->audience === PluginMenuAction::AUDIENCE_PUBLIC) {
            return $action->label;
        }

        if ($action->anchor === '#create') {
            return __('workspace.mega.create', ['plugin' => $plugin->label()]);
        }

        if (str_ends_with($action->routeName, '.index') && ($action->anchor === null || $action->anchor === '')) {
            return __('workspace.mega.view', ['plugin' => $plugin->label()]);
        }

        return $action->label;
    }

    private function actionDescription(PluginMenuAction $action): ?string
    {
        if ($action->descriptionKey !== null && $action->descriptionKey !== '') {
            $translated = __($action->descriptionKey);
            if ($translated !== $action->descriptionKey) {
                return $translated;
            }
        }

        return $action->description;
    }
}
