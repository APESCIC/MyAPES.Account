<?php

namespace App\Core\Providers;

use App\Core\Access\GroupAccessMatrix;
use App\Core\Accounts\ClientNote;
use App\Core\Accounts\User;
use App\Core\Attachments\SupportAttachment;
use App\Core\Attachments\SupportAttachmentPolicy;
use App\Policies\ClientNotePolicy;
use App\Services\ApplicationAuthorizationGate;
use App\Support\Navigation\NavigationPermissionCache;
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
        $this->app->scoped(NavigationPermissionCache::class);
    }

    public function boot(): void
    {
        Gate::policy(SupportAttachment::class, SupportAttachmentPolicy::class);
        Gate::policy(ClientNote::class, ClientNotePolicy::class);

        Gate::before(
            static fn (User $user, string $ability): ?bool => app(
                ApplicationAuthorizationGate::class,
            )->authorize($user, $ability),
        );
    }
}
