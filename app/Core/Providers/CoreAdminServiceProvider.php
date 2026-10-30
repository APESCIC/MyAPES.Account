<?php

namespace App\Core\Providers;

use App\Contracts\MaintenanceModeGateway;
use App\Core\Extensions\Navigation\PublicNavigationRegistry;
use App\Services\LaravelMaintenanceModeGateway;
use App\Support\MascotTips;
use App\Support\Navigation\WorkspaceNavigation;
use App\Support\ReleaseHistoryRepository;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as IlluminateView;

/**
 * Core Admin shell / layout composition (#282).
 *
 * Navigation comes from module + plugin contracts — never plugin models.
 */
class CoreAdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MaintenanceModeGateway::class, LaravelMaintenanceModeGateway::class);
        $this->app->singleton(ReleaseHistoryRepository::class);
    }

    public function boot(): void
    {
        View::composer(['layouts.app', 'auth.landing'], function (IlluminateView $view): void {
            $view->with('appVersion', app(ReleaseHistoryRepository::class)->version());
            $view->with('mascotTip', app(MascotTips::class)->forCurrentRequest());
            $view->with(
                'publicPluginNavigation',
                app(PublicNavigationRegistry::class)->visibleTo(auth()->user()),
            );
        });

        View::composer('layouts.app', function (IlluminateView $view): void {
            $request = request();
            $user = $request->hasSession() ? auth()->user() : null;

            $view->with(
                'workspaceMenu',
                app(WorkspaceNavigation::class)->forRequest($user, $request),
            );
        });
    }
}
