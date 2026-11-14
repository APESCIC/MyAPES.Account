<?php

namespace App\Core\Providers;

use App\Core\Accounts\ClientRecords\ClientRecordRegistry;
use App\Core\Attachments\Scanning\ClamdScanner;
use App\Core\Attachments\Scanning\DisabledScanner;
use App\Core\Attachments\Scanning\FakeScanner;
use App\Core\Attachments\Scanning\VirusScanner;
use App\Core\Eloquent\MorphMap;
use App\Core\Integrations\CoreIntegrations;
use App\Core\Integrations\IntegrationRegistry;
use App\Core\Settings\AppSettings;
use App\Core\Settings\GeneralSettings;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ClientRecordRegistry::class);
        $this->app->singleton(AppSettings::class);
        $this->app->afterResolving(AppSettings::class, static fn (AppSettings $settings) => $settings->define(...GeneralSettings::definitions()));
        $this->app->singleton(IntegrationRegistry::class);
        $this->app->afterResolving(IntegrationRegistry::class, static fn (IntegrationRegistry $registry) => $registry->register(...CoreIntegrations::all()));
        $this->app->singleton(VirusScanner::class, static fn (): VirusScanner => match (config('myapes.attachments.scanner')) {
            'clamd' => new ClamdScanner(
                (string) config('myapes.attachments.clamd.host', ''),
                (int) config('myapes.attachments.clamd.port', 3310),
                (int) config('myapes.attachments.clamd.timeout', 30),
            ),
            'fake' => new FakeScanner,
            default => new DisabledScanner,
        });
    }

    public function boot(): void
    {
        MorphMap::enforce();
    }
}
