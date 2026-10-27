<?php

namespace App\Modules;

use App\Contracts\ModuleRegistry;
use App\Core\Access\PermissionNaming;
use App\Core\Extensions\Modules\ModuleManifest;
use App\Core\Extensions\Modules\ModulePackageRegistry;
use App\Core\Extensions\Plugins\PluginAbility;
use App\Core\Extensions\Plugins\PluginManifest;
use App\Core\Extensions\Plugins\PluginRegistry;
use App\Services\ModuleRegistryValidator;
use App\Support\ReleaseHistoryRepository;
use InvalidArgumentException;
use Plugins\PetProfiles\Dashboard\PetProfileAnalyticsProvider;
use Plugins\PetProfiles\Dashboard\PetProfileRecentActivityProvider;

/**
 * Compatibility adapter that builds the legacy ModuleRegistry view from
 * package manifests (#283 / #287). FirstParty hard-coded buildSubCores /
 * buildModules lists are gone.
 */
final class FirstPartyModuleRegistry implements ModuleRegistry
{
    /** @var array<string, SubCoreDefinition> */
    private array $subCores;

    /** @var array<string, ModuleDefinition> */
    private array $modules;

    /** @var array<string, ModuleInstanceDefinition> */
    private array $matrix;

    /** @var array<string, ModulePermissionDescriptor> */
    private array $permissions;

    public function __construct(
        ModulePackageRegistry $modulePackages,
        PluginRegistry $plugins,
        ModuleRegistryValidator $validator,
        ReleaseHistoryRepository $releases,
    ) {
        $applicationVersion = $releases->version();
        $moduleSlugs = array_keys($modulePackages->modules());

        $plugins->validate($moduleSlugs, $applicationVersion);

        $this->subCores = $this->adaptSubCores($modulePackages);
        $this->modules = $this->adaptModules($plugins);
        $this->matrix = $this->buildMatrix($plugins);
        $this->permissions = $this->buildPermissions();
        $validator->validate(
            $this->subCores,
            $this->modules,
            $this->matrix,
        );
    }

    public function subCores(): array
    {
        return $this->subCores;
    }

    public function modules(): array
    {
        return $this->modules;
    }

    public function matrix(): array
    {
        return array_values($this->matrix);
    }

    public function shippedInstances(): array
    {
        return array_filter(
            $this->matrix,
            static fn (ModuleInstanceDefinition $instance): bool => $instance->isShipped(),
        );
    }

    public function permissions(): array
    {
        return array_values($this->permissions);
    }

    public function subCore(string $key): SubCoreDefinition
    {
        return $this->subCores[$key]
            ?? throw new InvalidArgumentException('Unknown sub-core key.');
    }

    public function module(string $key): ModuleDefinition
    {
        return $this->modules[$key]
            ?? throw new InvalidArgumentException('Unknown module key.');
    }

    public function instance(
        string $subCoreKey,
        string $moduleKey,
    ): ModuleInstanceDefinition {
        return $this->matrix["{$subCoreKey}:{$moduleKey}"]
            ?? throw new InvalidArgumentException('Unknown module instance.');
    }

    public function recognizesPermission(string $permission): bool
    {
        return isset($this->permissions[$permission]);
    }

    public function permission(string $permission): ?ModulePermissionDescriptor
    {
        return $this->permissions[$permission] ?? null;
    }

    /** @return array<string, SubCoreDefinition> */
    private function adaptSubCores(ModulePackageRegistry $modulePackages): array
    {
        $keyed = [];

        foreach ($modulePackages->modules() as $manifest) {
            $keyed[$manifest->slug] = $this->toSubCore($manifest);
        }

        ksort($keyed);

        return $keyed;
    }

    private function toSubCore(ModuleManifest $manifest): SubCoreDefinition
    {
        return new SubCoreDefinition(
            $manifest->slug,
            $manifest->name,
            $manifest->description,
            $manifest->routePrefix,
            $manifest->hubRouteName,
            $manifest->icon,
            $manifest->sortOrder,
        );
    }

    /** @return array<string, ModuleDefinition> */
    private function adaptModules(PluginRegistry $plugins): array
    {
        $keyed = [];

        foreach ($plugins->plugins() as $manifest) {
            $keyed[$manifest->slug] = $this->toModuleDefinition($manifest);
        }

        ksort($keyed);

        return $keyed;
    }

    private function toModuleDefinition(PluginManifest $manifest): ModuleDefinition
    {
        $abilities = array_map(
            static fn (PluginAbility $ability): ModuleAbilityDefinition => new ModuleAbilityDefinition(
                $ability->ability,
                $ability->label,
                $ability->requiresDirectoryContext,
                $ability->defaultRoles,
            ),
            $manifest->permissions,
        );

        $navigation = [];
        foreach ($manifest->navigation as $item) {
            if ($item->moduleSlug === null) {
                continue;
            }

            $navigation[$item->moduleSlug] = new ModuleNavigationDefinition(
                $item->label,
                $item->routeName,
                $item->icon,
                $item->order,
                $item->requiredAbility,
            );
        }

        return new ModuleDefinition(
            $manifest->slug,
            $manifest->label(),
            $manifest->descriptionLabel(),
            $manifest->version,
            $manifest->compatibleModules === ['*']
                ? array_keys($this->subCores)
                : $manifest->compatibleModules,
            $manifest->shippedModules,
            $abilities,
            $navigation,
            $manifest->activeRecordDetector
                ?? throw new InvalidArgumentException("Plugin [{$manifest->slug}] missing activeRecordDetector."),
            $manifest->summaryProvider,
            $manifest->recentActivityProvider,
            $manifest->analyticsProvider,
            $manifest->attentionProvider,
        );
    }

    /** @return array<string, ModuleInstanceDefinition> */
    private function buildMatrix(PluginRegistry $plugins): array
    {
        $matrix = [];

        foreach ($this->subCores as $subCore) {
            foreach ($this->modules as $module) {
                $compatible = in_array(
                    $subCore->key,
                    $module->compatibleSubCores,
                    true,
                );
                $shipped = in_array(
                    $subCore->key,
                    $module->shippedSubCores,
                    true,
                );
                $status = $shipped
                    ? ModuleCodeStatus::Shipped
                    : ($compatible
                        ? ModuleCodeStatus::CodeNotShipped
                        : ModuleCodeStatus::Incompatible);

                $dependencies = $this->instanceDependencies($subCore->key, $module->key, $plugins);

                $instance = new ModuleInstanceDefinition(
                    $subCore,
                    $module,
                    $status,
                    $dependencies,
                    recentActivityProvider: in_array(
                        "{$subCore->key}:{$module->key}",
                        [
                            'shelter-rescue:pet-profiles',
                            'pet-care-clinic:pet-profiles',
                        ],
                        true,
                    )
                        ? PetProfileRecentActivityProvider::class
                        : null,
                    analyticsProvider: in_array(
                        "{$subCore->key}:{$module->key}",
                        [
                            'shelter-rescue:pet-profiles',
                            'pet-care-clinic:pet-profiles',
                        ],
                        true,
                    )
                        ? PetProfileAnalyticsProvider::class
                        : null,
                );
                $matrix[$instance->key()] = $instance;
            }
        }

        ksort($matrix);

        return $matrix;
    }

    /**
     * Build enablement dependencies from the plugin manifest (#284–#286).
     *
     * Only shipped module×plugin cells carry deps. PluginDependency::onlyModules
     * keeps historical narrower deps (Cases → Pet Profiles under shelter-rescue
     * only; APES CIC cases do not).
     *
     * @return list<ModuleDependency>
     */
    private function instanceDependencies(
        string $subCoreKey,
        string $moduleKey,
        PluginRegistry $plugins,
    ): array {
        if (! $plugins->has($moduleKey)) {
            return [];
        }

        $plugin = $plugins->plugin($moduleKey);

        if (! $plugin->isShippedFor($subCoreKey)) {
            return [];
        }

        $dependencies = [];

        foreach ($plugin->dependencies as $dependency) {
            if (! $dependency->appliesTo($subCoreKey)) {
                continue;
            }

            $dependencies[] = new ModuleDependency($subCoreKey, $dependency->pluginSlug);
        }

        return $dependencies;
    }

    /** @return array<string, ModulePermissionDescriptor> */
    private function buildPermissions(): array
    {
        $permissions = [];

        foreach ($this->shippedInstances() as $instance) {
            foreach ($instance->module->abilities as $ability) {
                $name = PermissionNaming::pluginPermission(
                    $instance->subCore->key,
                    $instance->module->key,
                    $ability->ability,
                );
                $permissions[$name] = new ModulePermissionDescriptor(
                    $name,
                    $instance->subCore->key,
                    $instance->module->key,
                    $ability->ability,
                    $ability->label,
                    $ability->requiresDirectoryContext,
                    $ability->defaultRoles,
                );
            }
        }

        ksort($permissions);

        return $permissions;
    }
}
