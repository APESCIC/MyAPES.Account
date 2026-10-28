<?php

namespace App\Core\Providers;

use App\Core\Mail\CoreMailTemplates;
use App\Core\Mail\MailTemplateRegistry;
use Illuminate\Support\ServiceProvider;

class CoreMailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MailTemplateRegistry::class);
    }

    public function boot(): void
    {
        $this->app->make(MailTemplateRegistry::class)
            ->contribute(static fn (): array => CoreMailTemplates::all());
    }
}
