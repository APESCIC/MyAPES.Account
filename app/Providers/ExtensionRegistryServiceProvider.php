<?php

namespace App\Providers;

use App\Contracts\ModuleLifecycleManager;
use App\Contracts\ModuleNavigationProvider;
use App\Contracts\ModuleRegistry;
use App\Core\Extensions\Modules\ModuleContext;
use App\Core\Extensions\Modules\ModulePackageRegistry;
use App\Core\Extensions\Navigation\PublicNavigationRegistry;
use App\Core\Extensions\Navigation\StaffPluginNavigationRegistry;
use App\Core\Extensions\Plugins\PluginEnablement;
use App\Core\Extensions\Plugins\PluginRegistry;
use App\Core\Extensions\Settings\ManagedListRegistry;
use App\Modules\FirstPartyModuleRegistry;
use App\Services\AuthorizationProfile;
use App\Services\DatabaseModuleLifecycleManager;
use App\Services\RegistryModuleNavigationProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Binds Core extension registries (modules + plugins) (#283 / #287).
 *
 * Renamed from the old ModuleServiceProvider binder so the Core abstract
 * ModuleServiceProvider base can own that name in App\Core\Extensions\Modules.
 */
class ExtensionRegistryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModulePackageRegistry::class);
        $this->app->singleton(PluginRegistry::class);
        $this->app->singleton(PublicNavigationRegistry::class);
        $this->app->singleton(StaffPluginNavigationRegistry::class);
        $this->app->singleton(ManagedListRegistry::class);

        $this->app->singleton(
            ModuleRegistry::class,
            FirstPartyModuleRegistry::class,
        );
        $this->app->scoped(ModuleContext::class);
        $this->app->scoped(AuthorizationProfile::class);
        $this->app->bind(
            ModuleLifecycleManager::class,
            DatabaseModuleLifecycleManager::class,
        );
        $this->app->singleton(PluginEnablement::class);
        $this->app->bind(
            ModuleNavigationProvider::class,
            RegistryModuleNavigationProvider::class,
        );
    }
}
