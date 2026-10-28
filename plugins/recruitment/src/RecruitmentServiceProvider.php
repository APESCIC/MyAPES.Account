<?php

namespace Plugins\Recruitment;

use App\Core\Extensions\Navigation\PublicNavigationItem;
use App\Core\Extensions\Navigation\PublicNavigationRegistry;
use App\Core\Extensions\Navigation\StaffPluginNavigationItem;
use App\Core\Extensions\Navigation\StaffPluginNavigationRegistry;
use App\Core\Extensions\Plugins\PluginAbilityFactory;
use App\Core\Extensions\Plugins\PluginManifest;
use App\Core\Extensions\Plugins\PluginNavigationItem;
use App\Core\Extensions\Plugins\PluginServiceProvider;
use App\Core\Extensions\Plugins\PluginSettingsSchema;
use App\Core\Mail\MailCategory;
use App\Core\Mail\MailTemplate;
use App\Services\ModuleCatalogueProjection;
use App\Services\ModuleSettingsService;
use Illuminate\Support\Facades\Gate;
use Plugins\Recruitment\Dashboard\RecruitmentActiveRecordDetector;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Recruitment\Models\RecruitmentRole;
use Plugins\Recruitment\Navigation\RecruitmentPrimaryNav;
use Plugins\Recruitment\Notifications\RecruitmentApplicationReceivedNotification;
use Plugins\Recruitment\Notifications\RecruitmentApplicationStatusNotification;
use Plugins\Recruitment\Policies\RecruitmentApplicationPolicy;
use Plugins\Recruitment\Policies\RecruitmentRolePolicy;

class RecruitmentServiceProvider extends PluginServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'recruitment');

        Gate::policy(RecruitmentRole::class, RecruitmentRolePolicy::class);
        Gate::policy(RecruitmentApplication::class, RecruitmentApplicationPolicy::class);

        $this->registerPublicNavigation();
        $this->registerStaffNavigation();
    }

    protected function manifest(): PluginManifest
    {
        $a = PluginAbilityFactory::class;

        return PluginManifest::make(
            slug: 'recruitment',
            name: 'Recruitment',
            description: 'Staff, volunteering, and student roles for APES CIC.',
            version: '1.0.0',
            requiresCore: '^0.37.0',
            compatibleModules: ['apes-cic'],
            shippedModules: ['apes-cic'],
            permissions: [
                $a::applicant('view-own', 'View own recruitment items'),
                $a::manage('create', 'Create recruitment roles'),
                $a::manage('view-all', 'View all recruitment roles'),
                $a::manage('update', 'Update recruitment roles'),
                $a::manage('delete', 'Delete recruitment roles'),
                $a::manage('review-applications', 'Review recruitment applications'),
            ],
            navigation: [
                new PluginNavigationItem(
                    'Recruitment',
                    'apes-cic.recruitment.index',
                    'clipboard-list',
                    30,
                    requiredAbility: 'view-all',
                    moduleSlug: 'apes-cic',
                ),
            ],
            settingsByModule: [
                'apes-cic' => new PluginSettingsSchema(
                    supportsSettings: true,
                    schema: PluginSettingsSchema::SCHEMA_RECRUITMENT_BOARD,
                ),
            ],
            translationNamespace: 'recruitment',
            searchKeywordsKey: 'recruitment::plugin.keywords',
            activeRecordDetector: RecruitmentActiveRecordDetector::class,
            publicRouteFiles: [
                dirname(__DIR__).'/routes/public.php',
                dirname(__DIR__).'/routes/public-auth.php',
            ],
            staffRouteFiles: [
                dirname(__DIR__).'/routes/apes-cic.php',
            ],
        );
    }

    protected function mailTemplates(): array
    {
        return [
            new MailTemplate(
                key: 'recruitment.application_received',
                notification: RecruitmentApplicationReceivedNotification::class,
                category: MailCategory::Service,
                labelKey: 'recruitment::mail.templates.application_received.name',
                descriptionKey: 'recruitment::mail.templates.application_received.description',
                sample: static fn (): RecruitmentApplicationReceivedNotification => new RecruitmentApplicationReceivedNotification(
                    self::sampleApplication(RecruitmentApplication::STATUS_SUBMITTED),
                ),
                owner: 'recruitment',
            ),
            new MailTemplate(
                key: 'recruitment.application_status',
                notification: RecruitmentApplicationStatusNotification::class,
                category: MailCategory::Optional,
                labelKey: 'recruitment::mail.templates.application_status.name',
                descriptionKey: 'recruitment::mail.templates.application_status.description',
                sample: static fn (): RecruitmentApplicationStatusNotification => new RecruitmentApplicationStatusNotification(
                    self::sampleApplication(RecruitmentApplication::STATUS_SHORTLISTED),
                    RecruitmentApplication::STATUS_SHORTLISTED,
                ),
                owner: 'recruitment',
            ),
        ];
    }

    private static function sampleApplication(string $status): RecruitmentApplication
    {
        return (new RecruitmentApplication)
            ->forceFill(['id' => 64, 'status' => $status])
            ->setRelation('recruitmentRole', (new RecruitmentRole)->forceFill(['title' => 'Sample volunteer role']));
    }

    private function registerPublicNavigation(): void
    {
        app(PublicNavigationRegistry::class)->contribute(function (): array {
            $enabled = false;

            try {
                $enabled = app(ModuleSettingsService::class)->recruitmentPublicBoardEnabled();
            } catch (\Throwable) {
                $enabled = false;
            }

            return [
                new PublicNavigationItem(
                    label: 'Recruitment',
                    routeName: 'recruitment.index',
                    icon: 'briefcase',
                    order: 40,
                    routeIsPattern: 'recruitment.*',
                    enabled: $enabled,
                    visibleToSignedIn: static fn (): bool => false,
                ),
            ];
        });
    }

    private function registerStaffNavigation(): void
    {
        app(StaffPluginNavigationRegistry::class)->contribute(function (): array {
            $enabled = false;

            try {
                $enabled = in_array(
                    'apes-cic:recruitment',
                    app(ModuleCatalogueProjection::class)->enabledInstanceKeys(),
                    true,
                );
            } catch (\Throwable) {
                $enabled = false;
            }

            return [
                new StaffPluginNavigationItem(
                    label: 'Recruitment',
                    icon: 'briefcase',
                    order: 35,
                    routeIsPattern: ['apes-cic.recruitment.*', 'recruitment.*'],
                    abilities: [],
                    enabled: $enabled,
                    homeRouteName: 'apes-cic.recruitment.index',
                    destination: static fn ($user): ?string => app(RecruitmentPrimaryNav::class)->destinationFor($user),
                ),
            ];
        });
    }
}
