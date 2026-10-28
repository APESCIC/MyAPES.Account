<?php

namespace Plugins\Consultations;

use App\Core\Accounts\User;
use App\Core\Extensions\Plugins\PluginAbilityFactory;
use App\Core\Extensions\Plugins\PluginDependency;
use App\Core\Extensions\Plugins\PluginManifest;
use App\Core\Extensions\Plugins\PluginNavigationItem;
use App\Core\Extensions\Plugins\PluginServiceProvider;
use App\Core\Extensions\Plugins\PluginSettingsSchema;
use App\Core\Mail\MailCategory;
use App\Core\Mail\MailTemplate;
use Illuminate\Support\Facades\Gate;
use Plugins\Consultations\Dashboard\ConsultationAttentionProvider;
use Plugins\Consultations\Dashboard\PetCareConsultationActiveRecordDetector;
use Plugins\Consultations\Dashboard\PetCareConsultationAnalyticsProvider;
use Plugins\Consultations\Dashboard\PetCareConsultationRecentActivityProvider;
use Plugins\Consultations\Dashboard\PetCareConsultationSummaryProvider;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\Consultations\Notifications\ConsultationUpdatedNotification;
use Plugins\Consultations\Policies\PetCareConsultationPolicy;

class ConsultationsServiceProvider extends PluginServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'consultations');

        Gate::policy(PetCareConsultation::class, PetCareConsultationPolicy::class);
    }

    protected function mailTemplates(): array
    {
        return [
            new MailTemplate(
                key: 'consultations.consultation_updated',
                notification: ConsultationUpdatedNotification::class,
                category: MailCategory::Service,
                labelKey: 'consultations::mail.templates.consultation_updated.name',
                descriptionKey: 'consultations::mail.templates.consultation_updated.description',
                sample: static fn (User $recipient): ConsultationUpdatedNotification => new ConsultationUpdatedNotification(
                    (new PetCareConsultation)->forceFill([
                        'id' => 128,
                        'subject' => 'Sample consultation',
                        'status' => 'open',
                    ]),
                    $recipient,
                    'updated',
                ),
                owner: 'consultations',
            ),
        ];
    }

    protected function manifest(): PluginManifest
    {
        $a = PluginAbilityFactory::class;

        return PluginManifest::make(
            slug: 'consultations',
            name: 'Consultations',
            description: 'Pet care consultation records and follow-up.',
            version: '1.0.0',
            requiresCore: '^0.37.0',
            compatibleModules: ['pet-care-clinic'],
            shippedModules: ['pet-care-clinic'],
            permissions: [
                $a::public('view-own', 'View own consultations'),
                $a::public('create', 'Create consultations'),
                $a::public('update-own', 'Update own consultations'),
                $a::staff('view-all', 'View all consultations'),
                $a::staff('update-all', 'Update all consultations'),
                $a::staff('assign', 'Assign consultations'),
                $a::staff('close', 'Close consultations'),
            ],
            dependencies: [
                new PluginDependency('pet-profiles'),
            ],
            navigation: [
                new PluginNavigationItem(
                    'Consultations',
                    'petcare.consultations.index',
                    'messages-square',
                    30,
                    moduleSlug: 'pet-care-clinic',
                ),
            ],
            settingsByModule: [
                'pet-care-clinic' => new PluginSettingsSchema(supportsSettings: false),
            ],
            translationNamespace: 'consultations',
            searchKeywordsKey: 'consultations::plugin.keywords',
            activeRecordDetector: PetCareConsultationActiveRecordDetector::class,
            summaryProvider: PetCareConsultationSummaryProvider::class,
            recentActivityProvider: PetCareConsultationRecentActivityProvider::class,
            analyticsProvider: PetCareConsultationAnalyticsProvider::class,
            attentionProvider: ConsultationAttentionProvider::class,
            staffRouteFiles: [
                dirname(__DIR__).'/routes/petcare.php',
            ],
        );
    }
}
