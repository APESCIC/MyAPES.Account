<?php

namespace App\Core\Providers;

use App\Core\Accounts\ClientRecords\ClientRecordRegistry;
use App\Core\Eloquent\MorphMap;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ClientRecordRegistry::class);
    }

    public function boot(): void
    {
        MorphMap::enforce();
    }
}
