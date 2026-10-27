<?php

namespace App\Services;

use App\Contracts\ModuleNavigationProvider;
use App\Contracts\ModuleRegistry;
use App\Core\Accounts\User;
use App\Core\Extensions\Modules\ModulePackageRegistry;
use App\Modules\ModuleNavigationItem;
use App\Modules\SubCoreNavigation;
use Illuminate\Http\Request;

class RegistryModuleNavigationProvider implements ModuleNavigationProvider
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModulePackageRegistry $modulePackages,
        private readonly ModuleCatalogueProjection $projection,
        private readonly ServiceEntitlement $entitlement,
        private readonly Request $request,
    ) {}

    public function forUser(User $user): array
    {
        $navigation = [];

        foreach ($this->registry->subCores() as $subCore) {
            if (! $this->modulePackages->isEnabled($subCore->key)) {
                continue;
            }

            if (! $this->entitlement->allows($user, $subCore->key, $this->request)) {
                continue;
            }

            $modules = $this->forSubCore($user, $subCore->key);

            if ($modules !== []) {
                $navigation[] = new SubCoreNavigation($subCore, $modules);
            }
        }

        usort(
            $navigation,
            static fn (SubCoreNavigation $left, SubCoreNavigation $right): int => $left->subCore->navigationOrder
                <=> $right->subCore->navigationOrder,
        );

        return $navigation;
    }

    public function forSubCore(User $user, string $subCoreKey): array
    {
        if (! $this->modulePackages->isEnabled($subCoreKey)) {
            return [];
        }

        $enabled = array_flip($this->projection->enabledInstanceKeys());
        $items = [];

        foreach ($this->registry->shippedInstances() as $instance) {
            if ($instance->subCore->key !== $subCoreKey
                || ! isset($enabled[$instance->key()])) {
                continue;
            }

            $navigation = $instance->module
                ->navigation[$subCoreKey] ?? null;

            if ($navigation === null
                || ! $this->canView($user, $instance->key(), $navigation->requiredAbility)) {
                continue;
            }

            $items[] = new ModuleNavigationItem(
                $instance->key(),
                $subCoreKey,
                $instance->module->key,
                $navigation->label,
                $navigation->routeName,
                $navigation->icon,
                $navigation->order,
            );
        }

        usort(
            $items,
            static fn (ModuleNavigationItem $left, ModuleNavigationItem $right): int => $left->order <=> $right->order,
        );

        return $items;
    }

    private function canView(User $user, string $instanceKey, ?string $requiredAbility): bool
    {
        [$subCore, $module] = explode(':', $instanceKey, 2);

        if ($requiredAbility !== null) {
            return $user->can("{$subCore}.{$module}.{$requiredAbility}");
        }

        return $user->can("{$subCore}.{$module}.view-own")
            || $user->can("{$subCore}.{$module}.view-all");
    }
}
