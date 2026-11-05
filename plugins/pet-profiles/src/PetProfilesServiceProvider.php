<?php

namespace Plugins\PetProfiles;

use App\Core\Accounts\ClientRecords\ClientRecordType;
use App\Core\Extensions\Plugins\PluginAbilityFactory;
use App\Core\Extensions\Plugins\PluginManifest;
use App\Core\Extensions\Plugins\PluginNavigationItem;
use App\Core\Extensions\Plugins\PluginServiceProvider;
use App\Core\Extensions\Plugins\PluginSettingsSchema;
use Illuminate\Support\Facades\Gate;
use Plugins\PetProfiles\Contracts\PetProfilesContract;
use Plugins\PetProfiles\Dashboard\PetProfileActiveRecordDetector;
use Plugins\PetProfiles\Dashboard\PetProfileAnalyticsProvider;
use Plugins\PetProfiles\Dashboard\PetProfileRecentActivityProvider;
use Plugins\PetProfiles\Dashboard\PetProfileSummaryProvider;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\PetProfiles\Policies\PetProfilePolicy;
use Plugins\PetProfiles\Services\PetProfiles;

class PetProfilesServiceProvider extends PluginServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->singleton(PetProfilesContract::class, PetProfiles::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'pet-profiles');

        Gate::policy(PetProfile::class, PetProfilePolicy::class);
    }

    protected function clientRecords(): array
    {
        return [new ClientRecordType('pet-profiles', 'Pet profiles', PetProfile::class)];
    }

    protected function manifest(): PluginManifest
    {
        $a = PluginAbilityFactory::class;

        return PluginManifest::make(
            slug: 'pet-profiles',
            name: 'Pet Profiles',
            description: 'Animal identity, care and welfare profiles.',
            version: '1.0.0',
            requiresCore: '^0.37.0',
            compatibleModules: ['shelter-rescue', 'pet-care-clinic'],
            shippedModules: ['shelter-rescue', 'pet-care-clinic'],
            permissions: [
                $a::client('view-own', 'View own pet profiles'),
                $a::client('create', 'Create pet profiles'),
                $a::public('update-own', 'Update own pet profiles'),
                $a::staff('view-all', 'View all pet profiles'),
                $a::staff('update-all', 'Update all pet profiles'),
            ],
            navigation: [
                new PluginNavigationItem('Pet Profiles', 'shelter.pets.index', 'paw-print', 10, moduleSlug: 'shelter-rescue'),
                new PluginNavigationItem('Pet Profiles', 'petcare.pets.index', 'paw-print', 10, moduleSlug: 'pet-care-clinic'),
            ],
            settingsByModule: [
                'shelter-rescue' => new PluginSettingsSchema(supportsSettings: false),
                'pet-care-clinic' => new PluginSettingsSchema(supportsSettings: false),
            ],
            translationNamespace: 'pet_profiles',
            searchKeywordsKey: 'pet_profiles::plugin.keywords',
            activeRecordDetector: PetProfileActiveRecordDetector::class,
            summaryProvider: PetProfileSummaryProvider::class,
            recentActivityProvider: PetProfileRecentActivityProvider::class,
            analyticsProvider: PetProfileAnalyticsProvider::class,
            staffRouteFiles: [
                dirname(__DIR__).'/routes/shelter.php',
                dirname(__DIR__).'/routes/petcare.php',
            ],
        );
    }
}
