<?php

namespace App\Core\Providers;

use App\Core\Access\GroupAccessMatrix;
use App\Core\Accounts\User;
use App\Services\ApplicationAuthorizationGate;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Core access gate wiring (#282). Plugin policies register on plugin providers.
 */
class CoreAccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(GroupAccessMatrix::class);
    }

    public function boot(): void
    {
        Gate::before(
            static fn (User $user, string $ability): ?bool => app(
                ApplicationAuthorizationGate::class,
            )->authorize($user, $ability),
        );
    }
}
