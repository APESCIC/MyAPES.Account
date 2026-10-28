<?php

namespace Plugins\Cases;

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
use Plugins\Cases\Dashboard\CaseAnalyticsProvider;
use Plugins\Cases\Dashboard\CaseAttentionProvider;
use Plugins\Cases\Dashboard\CaseRecentActivityProvider;
use Plugins\Cases\Dashboard\ShelterCaseActiveRecordDetector;
use Plugins\Cases\Dashboard\ShelterCaseSummaryProvider;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Cases\Notifications\ApesCicCaseUpdatedNotification;
use Plugins\Cases\Notifications\ShelterCaseUpdatedNotification;
use Plugins\Cases\Policies\ShelterCasePolicy;

class CasesServiceProvider extends PluginServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cases');

        Gate::policy(ShelterCase::class, ShelterCasePolicy::class);
    }

    protected function mailTemplates(): array
    {
        return [
            new MailTemplate(
                key: 'cases.apes_cic_case_updated',
                notification: ApesCicCaseUpdatedNotification::class,
                category: MailCategory::Service,
                labelKey: 'cases::mail.templates.apes_cic_case_updated.name',
                descriptionKey: 'cases::mail.templates.apes_cic_case_updated.description',
                sample: static fn (User $recipient): ApesCicCaseUpdatedNotification => new ApesCicCaseUpdatedNotification(
                    (new ShelterCase)->forceFill([
                        'id' => 512,
                        'status' => 'open',
                        'priority' => 'medium',
                    ]),
                    $recipient,
                    'updated',
                    'apes-cic',
                    'apes-cic.cases.show',
                ),
                owner: 'cases',
                subjectKey: 'mail.case_updated.subject',
                introKey: 'mail.case_updated.line_body',
            ),
            new MailTemplate(
                key: 'cases.shelter_case_updated',
                notification: ShelterCaseUpdatedNotification::class,
                category: MailCategory::Service,
                labelKey: 'cases::mail.templates.shelter_case_updated.name',
                descriptionKey: 'cases::mail.templates.shelter_case_updated.description',
                sample: static fn (User $recipient): ShelterCaseUpdatedNotification => new ShelterCaseUpdatedNotification(
                    (new ShelterCase)->forceFill([
                        'id' => 256,
                        'title' => 'Sample rescue case',
                        'case_type' => 'rescue',
                        'status' => 'open',
                    ]),
                    $recipient,
                    'updated',
                ),
                owner: 'cases',
                subjectKey: 'mail.shelter_case_updated.subject',
                introKey: 'mail.shelter_case_updated.line_body',
            ),
        ];
    }

    protected function manifest(): PluginManifest
    {
        $a = PluginAbilityFactory::class;

        return PluginManifest::make(
            slug: 'cases',
            name: 'Cases',
            description: 'Rescue and welfare case records.',
            version: '1.0.0',
            requiresCore: '^0.37.0',
            compatibleModules: ['apes-cic', 'shelter-rescue'],
            shippedModules: ['apes-cic', 'shelter-rescue'],
            permissions: [
                $a::public('view-own', 'View own cases'),
                $a::public('create', 'Create cases'),
                $a::public('update-own', 'Update own cases'),
                $a::public('comment-own', 'Comment on own cases'),
                $a::staff('view-all', 'View all cases'),
                $a::staff('update-all', 'Update all cases'),
                $a::staff('assign', 'Assign cases'),
                $a::staff('close', 'Close cases'),
                $a::staffDelete('delete', 'Delete cases'),
            ],
            // Shelter cases need Pet Profiles; APES CIC cases do not (#286 / #289).
            dependencies: [
                new PluginDependency('pet-profiles', onlyModules: ['shelter-rescue']),
            ],
            navigation: [
                new PluginNavigationItem('Cases', 'apes-cic.cases.index', 'briefcase-business', 20, moduleSlug: 'apes-cic'),
                new PluginNavigationItem('Cases', 'shelter.cases.index', 'house', 30, moduleSlug: 'shelter-rescue'),
            ],
            settingsByModule: [
                'apes-cic' => new PluginSettingsSchema(
                    supportsSettings: true,
                    schema: PluginSettingsSchema::SCHEMA_WEBSITES_CATEGORIES,
                    groupKey: 'categories',
                    groupLabel: 'Case categories',
                ),
                'shelter-rescue' => new PluginSettingsSchema(supportsSettings: false),
            ],
            translationNamespace: 'cases',
            searchKeywordsKey: 'cases::plugin.keywords',
            activeRecordDetector: ShelterCaseActiveRecordDetector::class,
            summaryProvider: ShelterCaseSummaryProvider::class,
            recentActivityProvider: CaseRecentActivityProvider::class,
            analyticsProvider: CaseAnalyticsProvider::class,
            attentionProvider: CaseAttentionProvider::class,
            staffRouteFiles: [
                dirname(__DIR__).'/routes/apes-cic.php',
                dirname(__DIR__).'/routes/shelter.php',
            ],
        );
    }
}
