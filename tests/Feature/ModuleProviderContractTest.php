<?php

namespace Tests\Feature;

use App\Contracts\ModuleAggregateSummaryProvider;
use App\Contracts\ModuleAnalyticsProvider;
use App\Contracts\ModuleAttentionProvider;
use App\Contracts\ModuleRecentActivityProvider;
use App\Contracts\ModuleRegistry;
use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Modules\ModuleAnalyticsSnapshot;
use App\Modules\ModuleInstanceDefinition;
use App\Modules\ModuleSummary;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use App\Services\ModuleProjectionCache;
use App\Services\ModuleRegistryValidator;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Plugins\Cases\Dashboard\CaseAttentionProvider;
use Plugins\Cases\Models\CaseUpdate;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Consultations\Dashboard\ConsultationAttentionProvider;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\PetProfiles\Dashboard\PetProfileAnalyticsProvider;
use Plugins\PetProfiles\Dashboard\PetProfileRecentActivityProvider;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\Tickets\Dashboard\SupportTicketAnalyticsProvider;
use Plugins\Tickets\Dashboard\SupportTicketAttentionProvider;
use Plugins\Tickets\Dashboard\SupportTicketRecentActivityProvider;
use Plugins\Tickets\Dashboard\SupportTicketSummaryProvider;
use Plugins\Tickets\Models\SupportTicket;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ModuleProviderContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_ticket_case_and_consultation_definitions_expose_optional_typed_provider_slots(): void
    {
        $registry = app(ModuleRegistry::class);

        foreach (['tickets', 'cases', 'consultations'] as $moduleKey) {
            $module = $registry->module($moduleKey);
            $this->assertTrue(is_a(
                $module->recentActivityProvider,
                ModuleRecentActivityProvider::class,
                true,
            ));
            $this->assertTrue(is_a(
                $module->analyticsProvider,
                ModuleAnalyticsProvider::class,
                true,
            ));
        }

        $this->assertSame(
            PetProfileRecentActivityProvider::class,
            $registry->module('pet-profiles')->recentActivityProvider,
        );
        $this->assertNull($registry->module('recruitment')->recentActivityProvider);
    }

    public function test_attention_providers_are_registered_for_open_item_modules_only(): void
    {
        $registry = app(ModuleRegistry::class);

        $this->assertSame(
            SupportTicketAttentionProvider::class,
            $registry->module('tickets')->attentionProvider,
        );
        $this->assertSame(
            CaseAttentionProvider::class,
            $registry->module('cases')->attentionProvider,
        );
        $this->assertSame(
            ConsultationAttentionProvider::class,
            $registry->module('consultations')->attentionProvider,
        );
        $this->assertNull($registry->module('pet-profiles')->attentionProvider);
        $this->assertNull($registry->module('recruitment')->attentionProvider);
    }

    public function test_disabled_pet_care_analytics_providers_return_canonical_empty_snapshots_with_retained_data(): void
    {
        $owner = User::factory()->create();
        $pet = $this->petProfileFor($owner, [
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Retained disabled analytics pet',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        $ticket = $this->ticketFor($owner, [
            'sub_core_key' => 'pet-care-clinic',
            'service_area' => 'appointment',
            'subject' => 'Retained disabled analytics ticket',
            'status' => 'closed',
            'created_at' => now()->subHour(),
            'updated_at' => now(),
            'closed_at' => now(),
        ]);
        $consultation = PetCareConsultation::query()->create([
            'pet_profile_id' => $pet->id,
            'user_id' => $owner->id,
            'subject' => 'Retained disabled analytics consultation',
            'status' => 'closed',
            'closed_at' => now(),
        ]);
        $consultation->timestamps = false;
        $consultation->forceFill([
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ])->saveQuietly();
        $consultation->timestamps = true;
        $registry = app(ModuleRegistry::class);

        foreach (['pet-profiles', 'tickets', 'consultations'] as $moduleKey) {
            ModuleInstallation::query()
                ->where('sub_core_key', 'pet-care-clinic')
                ->where('module_key', $moduleKey)
                ->update(['enabled' => false]);
            $instance = $registry->instance('pet-care-clinic', $moduleKey);
            /** @var ModuleAnalyticsProvider $provider */
            $provider = app($instance->analyticsProviderClass());

            $snapshot = $provider->snapshot(
                $instance,
                now()->subDay(),
                now()->addDay(),
                'Europe/London',
            );
            $this->assertSame($instance->key(), $snapshot->instanceKey);
            $this->assertSame(0, $snapshot->total);
            $this->assertSame(0, $snapshot->open);
            $this->assertSame(0, $snapshot->highOrUrgent);
            $this->assertSame(0, $snapshot->unassigned);
            $this->assertSame([], $snapshot->createdPerDay);
            $this->assertSame([], $snapshot->closedPerDay);
            $this->assertNull($snapshot->medianClosureMinutes);
            $this->assertSame(0, $snapshot->closureSampleSize);

            ModuleInstallation::query()
                ->where('sub_core_key', 'pet-care-clinic')
                ->where('module_key', $moduleKey)
                ->update(['enabled' => true]);
        }

        $this->assertDatabaseHas('pet_profiles', ['id' => $pet->id]);
        $this->assertDatabaseHas('support_tickets', ['id' => $ticket->id]);
        $this->assertDatabaseHas('pet_care_consultations', ['id' => $consultation->id]);
    }

    public function test_instance_provider_resolvers_prefer_an_override_and_fall_back_to_the_module_contract(): void
    {
        $registry = app(ModuleRegistry::class);
        $fallback = $registry->instance('apes-cic', 'tickets');
        $tickets = $registry->instance('shelter-rescue', 'tickets');
        $matrix = collect($registry->matrix())
            ->mapWithKeys(
                static fn (ModuleInstanceDefinition $instance): array => [
                    $instance->key() => $instance,
                ],
            )
            ->all();
        $matrix[$tickets->key()] = new ModuleInstanceDefinition(
            $tickets->subCore,
            $tickets->module,
            $tickets->codeStatus,
            $tickets->dependencies,
            OverrideSummaryProvider::class,
            OverrideRecentActivityProvider::class,
            OverrideAnalyticsProvider::class,
            OverrideAttentionProvider::class,
        );
        app(ModuleRegistryValidator::class)->validate(
            $registry->subCores(),
            $registry->modules(),
            $matrix,
        );
        $overridden = $matrix[$tickets->key()];

        $this->assertSame(
            OverrideSummaryProvider::class,
            $overridden->summaryProviderClass(),
        );
        $this->assertSame(
            OverrideRecentActivityProvider::class,
            $overridden->recentActivityProviderClass(),
        );
        $this->assertSame(
            OverrideAnalyticsProvider::class,
            $overridden->analyticsProviderClass(),
        );
        $this->assertSame(
            OverrideAttentionProvider::class,
            $overridden->attentionProviderClass(),
        );
        $this->assertSame(
            SupportTicketSummaryProvider::class,
            $fallback->summaryProviderClass(),
        );
        $this->assertSame(
            SupportTicketRecentActivityProvider::class,
            $fallback->recentActivityProviderClass(),
        );
        $this->assertSame(
            SupportTicketAnalyticsProvider::class,
            $fallback->analyticsProviderClass(),
        );
        $this->assertSame(
            SupportTicketAttentionProvider::class,
            $fallback->attentionProviderClass(),
        );
    }

    public function test_attention_provider_contract_exposes_the_global_six_item_limit(): void
    {
        $method = new \ReflectionMethod(
            ModuleAttentionProvider::class,
            'attention',
        );
        $parameter = $method->getParameters()[2];

        $this->assertSame('limit', $parameter->getName());
        $this->assertTrue($parameter->isDefaultValueAvailable());
        $this->assertSame(6, $parameter->getDefaultValue());
    }

    public function test_case_analytics_are_instance_scoped_and_exact_for_the_requested_range(): void
    {
        $owner = User::factory()->create();
        $registry = app(ModuleRegistry::class);
        $instance = $registry->instance('apes-cic', 'cases');
        Carbon::setTestNow('2026-08-03 12:00:00');
        $this->caseFor($owner, [
            'priority' => 'urgent',
            'created_at' => '2026-08-01 09:00:00',
            'updated_at' => '2026-08-01 09:00:00',
            'opened_at' => '2026-08-01 09:00:00',
        ]);
        $this->caseFor($owner, [
            'status' => 'closed',
            'assigned_to' => $owner->id,
            'created_at' => '2026-08-01 10:00:00',
            'updated_at' => '2026-08-02 10:00:00',
            'opened_at' => '2026-08-01 10:00:00',
            'closed_at' => '2026-08-02 10:00:00',
        ]);
        $this->caseFor($owner, [
            'sub_core_key' => 'shelter-rescue',
            'case_type' => 'rescue',
            'category' => null,
            'priority' => 'urgent',
        ]);

        /** @var ModuleAnalyticsProvider $provider */
        $provider = app($instance->analyticsProviderClass());
        $snapshot = $provider->snapshot(
            $instance,
            Carbon::parse('2026-08-01 00:00:00', 'Europe/London'),
            Carbon::parse('2026-08-04 00:00:00', 'Europe/London'),
            'Europe/London',
        );

        $this->assertSame(2, $snapshot->total);
        $this->assertSame(1, $snapshot->open);
        $this->assertSame(1, $snapshot->highOrUrgent);
        $this->assertSame(1, $snapshot->unassigned);
        $this->assertSame(['2026-08-01' => 2], $snapshot->createdPerDay);
        $this->assertSame(['2026-08-02' => 1], $snapshot->closedPerDay);
        $this->assertSame(1440.0, $snapshot->medianClosureMinutes);
        $this->assertSame(1, $snapshot->closureSampleSize);
    }

    public function test_apes_cic_case_analytics_use_the_first_terminal_timestamp(): void
    {
        $owner = User::factory()->create();
        $registry = app(ModuleRegistry::class);
        $instance = $registry->instance('apes-cic', 'cases');
        $this->caseFor($owner, [
            'status' => 'closed',
            'opened_at' => Carbon::parse('2026-08-01 00:30:00', 'UTC'),
            'resolved_at' => Carbon::parse('2026-08-02 00:30:00', 'UTC'),
            'closed_at' => Carbon::parse('2026-08-03 00:30:00', 'UTC'),
        ]);
        $this->caseFor($owner, [
            'status' => 'closed',
            'opened_at' => Carbon::parse('2026-08-02 00:00:00', 'UTC'),
            'closed_at' => Carbon::parse('2026-08-03 00:30:00', 'UTC'),
        ]);
        $this->caseFor($owner, [
            'status' => 'resolved',
            'opened_at' => Carbon::parse('2026-08-02 09:00:00', 'UTC'),
            'resolved_at' => Carbon::parse('2026-08-02 12:00:00', 'UTC'),
        ]);

        /** @var ModuleAnalyticsProvider $provider */
        $provider = app($instance->analyticsProviderClass());
        $snapshot = $provider->snapshot(
            $instance,
            Carbon::parse('2026-08-02 00:00:00', 'Europe/London'),
            Carbon::parse('2026-08-04 00:00:00', 'Europe/London'),
            'Europe/London',
        );

        $this->assertSame([
            '2026-08-02' => 2,
            '2026-08-03' => 1,
        ], $snapshot->closedPerDay);
        $this->assertSame(1440.0, $snapshot->medianClosureMinutes);
        $this->assertSame(3, $snapshot->closureSampleSize);
    }

    public function test_apes_cic_case_analytics_exclude_terminal_timestamp_at_exclusive_to_boundary(): void
    {
        $owner = User::factory()->create();
        $registry = app(ModuleRegistry::class);
        $instance = $registry->instance('apes-cic', 'cases');
        $this->caseFor($owner, [
            'status' => 'resolved',
            'opened_at' => Carbon::parse('2026-08-03 00:00:00', 'UTC'),
            'resolved_at' => Carbon::parse('2026-08-03 23:00:00', 'UTC'),
        ]);

        /** @var ModuleAnalyticsProvider $provider */
        $provider = app($instance->analyticsProviderClass());
        $snapshot = $provider->snapshot(
            $instance,
            Carbon::parse('2026-08-02 00:00:00', 'Europe/London'),
            Carbon::parse('2026-08-04 00:00:00', 'Europe/London'),
            'Europe/London',
        );

        $this->assertSame([], $snapshot->closedPerDay);
        $this->assertNull($snapshot->medianClosureMinutes);
        $this->assertSame(0, $snapshot->closureSampleSize);
    }

    public function test_apes_cic_case_summaries_treat_resolved_and_closed_cases_as_terminal(): void
    {
        $owner = User::factory()->create();
        $registry = app(ModuleRegistry::class);
        $instance = $registry->instance('apes-cic', 'cases');
        $this->caseFor($owner, ['status' => 'open']);
        $this->caseFor($owner, ['status' => 'in_progress']);
        $this->caseFor($owner, ['status' => 'resolved']);
        $this->caseFor($owner, ['status' => 'closed']);

        $this->actingAs($owner);
        /** @var ModuleAggregateSummaryProvider $provider */
        $provider = app($instance->summaryProviderClass());
        $summary = $provider->summarize($instance, $owner);

        $this->assertSame(4, $summary->total);
        $this->assertSame(2, $summary->active);
    }

    public function test_shelter_case_providers_exclude_non_shelter_and_missing_pet_cases(): void
    {
        Carbon::setTestNow('2026-08-14 12:00:00');
        $owner = User::factory()->create();
        $instance = app(ModuleRegistry::class)
            ->instance('shelter-rescue', 'cases');
        $shelterPet = $this->petProfileFor($owner);
        $petCarePet = $this->petProfileFor($owner, [
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Foreign Pet Care profile',
        ]);
        $visible = $this->caseFor($owner, [
            'sub_core_key' => ShelterCase::SUB_CORE_SHELTER_RESCUE,
            'pet_profile_id' => $shelterPet->id,
            'case_type' => 'rescue',
            'category' => null,
            'title' => 'Valid Shelter provider case',
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);
        $this->caseFor($owner, [
            'sub_core_key' => ShelterCase::SUB_CORE_SHELTER_RESCUE,
            'pet_profile_id' => $petCarePet->id,
            'case_type' => 'rescue',
            'category' => null,
            'title' => 'Foreign-domain Shelter provider case',
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);
        $this->caseFor($owner, [
            'sub_core_key' => ShelterCase::SUB_CORE_SHELTER_RESCUE,
            'pet_profile_id' => null,
            'case_type' => 'rescue',
            'category' => null,
            'title' => 'Missing-pet Shelter provider case',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $this->actingAs($owner);
        /** @var ModuleAggregateSummaryProvider $summaryProvider */
        $summaryProvider = app($instance->summaryProviderClass());
        /** @var ModuleRecentActivityProvider $activityProvider */
        $activityProvider = app($instance->recentActivityProviderClass());
        /** @var ModuleAnalyticsProvider $analyticsProvider */
        $analyticsProvider = app($instance->analyticsProviderClass());

        $summary = $summaryProvider->summarize($instance, $owner);
        $activity = $activityProvider->recent($instance, $owner);
        $analytics = $analyticsProvider->snapshot(
            $instance,
            now()->startOfDay(),
            now()->addDay()->startOfDay(),
            'Europe/London',
        );

        $this->assertSame(1, $summary->total);
        $this->assertSame(1, $summary->active);
        $this->assertCount(1, $activity);
        $this->assertSame($visible->id, $activity[0]->recordId);
        $this->assertSame('Valid Shelter provider case', $activity[0]->title);
        $this->assertSame(1, $analytics->total);
        $this->assertSame(1, $analytics->open);
    }

    public function test_ticket_analytics_are_instance_and_range_scoped_with_timezone_buckets(): void
    {
        $owner = User::factory()->create();
        $registry = app(ModuleRegistry::class);
        $instance = $registry->instance('apes-cic', 'tickets');
        $this->ticketFor($owner, [
            'priority' => 'urgent',
            'created_at' => Carbon::parse('2026-07-31 23:30:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-07-31 23:30:00', 'UTC'),
        ]);
        $this->ticketFor($owner, [
            'status' => 'closed',
            'assigned_to' => $owner->id,
            'created_at' => Carbon::parse('2026-08-01 23:30:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-08-02 00:30:00', 'UTC'),
            'closed_at' => Carbon::parse('2026-08-02 00:30:00', 'UTC'),
        ]);
        $this->ticketFor($owner, [
            'status' => 'closed',
            'created_at' => Carbon::parse('2026-07-31 22:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-08-01 01:00:00', 'UTC'),
            'closed_at' => Carbon::parse('2026-08-01 01:00:00', 'UTC'),
        ]);
        $this->ticketFor($owner, [
            'sub_core_key' => 'another-sub-core',
            'created_at' => Carbon::parse('2026-08-01 12:00:00', 'UTC'),
        ]);
        $this->ticketFor($owner, [
            'created_at' => Carbon::parse('2026-08-03 23:00:00', 'UTC'),
        ]);

        /** @var ModuleAnalyticsProvider $provider */
        $provider = app($instance->analyticsProviderClass());
        $snapshot = $provider->snapshot(
            $instance,
            Carbon::parse('2026-08-01 00:00:00', 'Europe/London'),
            Carbon::parse('2026-08-04 00:00:00', 'Europe/London'),
            'Europe/London',
        );

        $this->assertSame(2, $snapshot->total);
        $this->assertSame(1, $snapshot->open);
        $this->assertSame(1, $snapshot->highOrUrgent);
        $this->assertSame(1, $snapshot->unassigned);
        $this->assertSame([
            '2026-08-01' => 1,
            '2026-08-02' => 1,
        ], $snapshot->createdPerDay);
        $this->assertSame([
            '2026-08-01' => 1,
            '2026-08-02' => 1,
        ], $snapshot->closedPerDay);
        $this->assertSame(120.0, $snapshot->medianClosureMinutes);
        $this->assertSame(2, $snapshot->closureSampleSize);
    }

    public function test_case_analytics_return_empty_when_the_module_is_disabled(): void
    {
        $owner = User::factory()->create();
        $instance = app(ModuleRegistry::class)->instance('apes-cic', 'cases');
        $this->caseFor($owner);
        ModuleInstallation::query()
            ->where('sub_core_key', 'apes-cic')
            ->where('module_key', 'cases')
            ->update([
                'enabled' => false,
                'disabled_at' => now(),
            ]);

        $snapshot = app($instance->analyticsProviderClass())->snapshot(
            $instance,
            now()->subDays(7),
            now()->addDay(),
            'UTC',
        );

        $this->assertSame(0, $snapshot->total);
        $this->assertSame(0, $snapshot->currentlyOpen);
        $this->assertSame(0, $snapshot->currentlyHighOrUrgent);
        $this->assertSame(0, $snapshot->currentlyUnassigned);
    }

    public function test_ticket_summary_and_recent_activity_use_the_instance_routes(): void
    {
        $owner = User::factory()->create();
        $registry = app(ModuleRegistry::class);
        $shelterTicket = $this->ticketFor($owner, [
            'sub_core_key' => 'shelter-rescue',
            'service_area' => 'rescue',
            'subject' => 'Shelter activity route',
        ]);
        $petCareTicket = $this->ticketFor($owner, [
            'sub_core_key' => 'pet-care-clinic',
            'service_area' => 'appointment',
            'subject' => 'Pet Care activity route',
        ]);

        $this->actingAs($owner);
        foreach ([
            ['shelter-rescue', 'shelter.tickets', $shelterTicket],
            ['pet-care-clinic', 'petcare.tickets', $petCareTicket],
        ] as [$subCoreKey, $routePrefix, $ticket]) {
            $instance = $registry->instance($subCoreKey, 'tickets');
            /** @var ModuleAggregateSummaryProvider $summaryProvider */
            $summaryProvider = app($instance->summaryProviderClass());
            $summary = $summaryProvider->summarize($instance, $owner);
            /** @var ModuleRecentActivityProvider $activityProvider */
            $activityProvider = app($instance->recentActivityProviderClass());
            $activity = $activityProvider->recent($instance, $owner);
            /** @var ModuleAnalyticsProvider $analyticsProvider */
            $analyticsProvider = app($instance->analyticsProviderClass());
            $analytics = $analyticsProvider->snapshot(
                $instance,
                now()->subDay(),
                now()->addDay(),
                'Europe/London',
            );

            $this->assertSame('Tickets', $summary->label);
            $this->assertSame($subCoreKey, $summary->subCoreKey);
            $this->assertSame($instance->subCore->name, $summary->subCoreName);
            $this->assertSame($routePrefix.'.index', $summary->routeName);
            $this->assertSame($routePrefix.'.show', $activity[0]->routeName);
            $this->assertSame($ticket->id, $activity[0]->recordId);
            $this->assertSame($subCoreKey.':tickets', $analytics->instanceKey);
            $this->assertSame(1, $analytics->total);
        }
    }

    public function test_apes_cic_hub_activity_excludes_internal_update_content_for_owners(): void
    {
        $owner = User::factory()->create();
        $case = $this->caseFor($owner);
        CaseUpdate::create([
            'shelter_case_id' => $case->id,
            'user_id' => $owner->id,
            'body' => 'Public case progress',
            'visibility' => 'public',
        ]);
        CaseUpdate::create([
            'shelter_case_id' => $case->id,
            'user_id' => $owner->id,
            'body' => 'Private internal case note',
            'visibility' => 'internal',
        ]);

        $this->actingAs($owner)
            ->get(route('apes-cic.index'))
            ->assertOk()
            ->assertSee('Cases')
            ->assertSee('General APES CIC case')
            ->assertSee('Recent updates')
            ->assertDontSee('Public case progress')
            ->assertDontSee('Private internal case note');
    }

    public function test_dashboard_includes_visible_apes_cic_cases_in_totals_and_attention(): void
    {
        $owner = User::factory()->create();
        $case = $this->caseFor($owner, ['title' => 'Membership support needed']);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-module-instance="apes-cic:cases"', false)
            ->assertSee('Membership support needed')
            ->assertSee(route('apes-cic.cases.show', $case));
    }

    public function test_dashboard_service_summary_groups_modules_under_sub_core_headings(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('id="service-summary-apes-cic"', false);
        $response->assertSee('id="service-summary-shelter-rescue"', false);
        $response->assertSee('id="service-summary-pet-care-clinic"', false);
        $response->assertSee('data-sub-core="apes-cic"', false);
        $response->assertSee('service-summary__group--apes-cic', false);
        $response->assertSee('service-summary__group--shelter-rescue', false);
        $response->assertSee('service-summary__group--pet-care-clinic', false);
        $response->assertSee('Open service', false);
        $response->assertSee(route('apes-cic.index'), false);
        $response->assertSee(route('shelter.index'), false);
        $response->assertSee(route('petcare.index'), false);
        $response->assertSee('data-module-instance="apes-cic:tickets"', false);
        $response->assertSee('data-module-instance="shelter-rescue:tickets"', false);
        $response->assertSee('data-module-instance="pet-care-clinic:tickets"', false);
        $response->assertDontSee('Open module', false);
        $response->assertViewHas(
            'moduleSummaries',
            static function (array $groups): bool {
                $keys = array_map(
                    static fn ($group): string => $group->key,
                    $groups,
                );
                if ($keys !== [
                    'apes-cic',
                    'shelter-rescue',
                    'pet-care-clinic',
                ]) {
                    return false;
                }

                $routesByGroup = [];
                $labelsByGroup = [];
                foreach ($groups as $group) {
                    $routesByGroup[$group->key] = $group->routeName;
                    $labelsByGroup[$group->key] = array_map(
                        static fn ($summary): string => $summary->label,
                        $group->summaries,
                    );
                }

                return $routesByGroup === [
                    'apes-cic' => 'apes-cic.index',
                    'shelter-rescue' => 'shelter.index',
                    'pet-care-clinic' => 'petcare.index',
                ] && $labelsByGroup === [
                    'apes-cic' => ['Tickets', 'Cases'],
                    'shelter-rescue' => ['Pet profiles', 'Tickets', 'Cases'],
                    'pet-care-clinic' => [
                        'Pet profiles',
                        'Tickets',
                        'Consultations',
                    ],
                ];
            },
        );
    }

    public function test_dashboard_excludes_tickets_from_other_sub_cores(): void
    {
        $owner = User::factory()->create();
        $apesTicket = $this->ticketFor($owner, [
            'subject' => 'Visible APES ticket',
        ]);
        $this->ticketFor($owner, [
            'sub_core_key' => 'another-sub-core',
            'subject' => 'Foreign private ticket',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($apesTicket->subject)
            ->assertDontSee('Foreign private ticket');
    }

    public function test_dashboard_excludes_owned_module_records_without_a_view_capability(): void
    {
        $owner = User::factory()->create();
        $ticket = $this->ticketFor($owner, [
            'subject' => 'Permission-gated private ticket',
        ]);
        $role = Role::query()
            ->where('guard_name', 'web')
            ->where('name', AuthorizationProfile::ROLE_SERVICE_USER)
            ->firstOrFail();
        $permission = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'apes-cic.tickets.view-own')
            ->firstOrFail();
        $role->permissions()->detach($permission->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($owner->fresh())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee($ticket->subject);
    }

    public function test_hub_merges_only_the_five_latest_accessible_instance_records(): void
    {
        Carbon::setTestNow('2026-08-10 12:00:00');
        $owner = User::factory()->create();
        foreach (range(1, 3) as $offset) {
            $this->ticketFor($owner, [
                'subject' => "Ticket activity {$offset}",
                'created_at' => now()->subMinutes($offset * 2),
                'updated_at' => now()->subMinutes($offset * 2),
            ]);
            $this->caseFor($owner, [
                'title' => "Case activity {$offset}",
                'created_at' => now()->subMinutes(($offset * 2) - 1),
                'updated_at' => now()->subMinutes(($offset * 2) - 1),
            ]);
        }
        $this->ticketFor($owner, [
            'sub_core_key' => 'another-sub-core',
            'subject' => 'Foreign activity title',
            'created_at' => now()->addMinute(),
            'updated_at' => now()->addMinute(),
        ]);

        $response = $this->actingAs($owner)->get(route('apes-cic.index'));

        $response->assertOk()
            ->assertSee('MyAPES Account service')
            ->assertSee('Available plugins')
            ->assertSee('What needs your attention')
            ->assertSee('Recent updates')
            ->assertSee('service-summary__number', false)
            ->assertSee('Ticket activity 1')
            ->assertSee('Case activity 1')
            ->assertSee('Ticket activity 2')
            ->assertSee('Case activity 2')
            ->assertSee('Case activity 3')
            ->assertDontSee('Foreign activity title')
            ->assertDontSee('Available modules')
            ->assertDontSee('MyAPES Account sub-core')
            ->assertDontSee('Available support services')
            ->assertDontSee('MyAPES support service')
            ->assertSee('attention-item__icon', false)
            ->assertSee('attention-item__meta', false)
            ->assertSee('attention-item__chevron', false)
            ->assertViewHas(
                'recentActivity',
                static function ($items): bool {
                    if ($items->count() !== 5) {
                        return false;
                    }

                    $titles = $items->pluck('title')->all();

                    return in_array('Case activity 1', $titles, true)
                        && in_array('Ticket activity 1', $titles, true)
                        && in_array('Case activity 2', $titles, true)
                        && in_array('Ticket activity 2', $titles, true)
                        && in_array('Case activity 3', $titles, true)
                        && ! in_array('Ticket activity 3', $titles, true);
                },
            );
    }

    public function test_service_hub_attention_is_scoped_to_the_active_service(): void
    {
        $owner = User::factory()->create();
        $apesTicket = $this->ticketFor($owner, [
            'subject' => 'APES CIC attention item',
        ]);
        $shelterTicket = $this->ticketFor($owner, [
            'sub_core_key' => 'shelter-rescue',
            'service_area' => 'rescue',
            'subject' => 'Shelter attention item',
        ]);

        $this->actingAs($owner)
            ->get(route('apes-cic.index'))
            ->assertOk()
            ->assertSee($apesTicket->subject)
            ->assertDontSee($shelterTicket->subject)
            ->assertViewHas(
                'attentionItems',
                static function (array $items) use ($apesTicket, $shelterTicket): bool {
                    $titles = array_map(
                        static fn ($item): string => $item->title,
                        $items,
                    );

                    return in_array($apesTicket->subject, $titles, true)
                        && ! in_array($shelterTicket->subject, $titles, true);
                },
            );
    }

    public function test_hub_recent_activity_uses_full_attention_row_layout_on_all_sub_cores(): void
    {
        $owner = User::factory()->create();
        $this->ticketFor($owner, [
            'sub_core_key' => 'shelter-rescue',
            'service_area' => 'rescue',
            'subject' => 'Shelter hub activity row',
        ]);
        $this->ticketFor($owner, [
            'sub_core_key' => 'pet-care-clinic',
            'service_area' => 'appointment',
            'subject' => 'Pet Care hub activity row',
        ]);

        foreach (['shelter.index', 'petcare.index'] as $routeName) {
            $this->actingAs($owner)
                ->get(route($routeName))
                ->assertOk()
                ->assertSee('attention-item__icon', false)
                ->assertSee('attention-item__meta', false)
                ->assertSee('attention-item__chevron', false);
        }
    }

    public function test_disabled_module_is_absent_from_hub_cards_and_activity(): void
    {
        $owner = User::factory()->create();
        $this->ticketFor($owner, ['subject' => 'Enabled ticket activity']);
        $this->caseFor($owner, ['title' => 'Disabled case activity']);
        ModuleInstallation::query()
            ->where('sub_core_key', 'apes-cic')
            ->where('module_key', 'cases')
            ->update([
                'enabled' => false,
                'disabled_at' => now(),
                'updated_at' => now(),
            ]);

        $this->actingAs($owner)
            ->get(route('apes-cic.index'))
            ->assertOk()
            ->assertSee('data-module-instance="apes-cic:tickets"', false)
            ->assertDontSee('data-module-instance="apes-cic:cases"', false)
            ->assertSee('Enabled ticket activity')
            ->assertDontSee('Disabled case activity');
    }

    public function test_stale_enabled_projection_cannot_render_disabled_pet_care_summaries_or_activity(): void
    {
        $owner = User::factory()->create();
        $pet = $this->petProfileFor($owner, [
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Retained lifecycle pet',
        ]);
        $ticket = $this->ticketFor($owner, [
            'sub_core_key' => 'pet-care-clinic',
            'service_area' => 'appointment',
            'subject' => 'Retained disabled ticket title',
            'status' => 'closed',
            'closed_at' => now(),
        ]);
        $consultation = PetCareConsultation::query()->create([
            'pet_profile_id' => $pet->id,
            'user_id' => $owner->id,
            'subject' => 'Retained disabled consultation title',
            'status' => 'closed',
            'closed_at' => now(),
        ]);
        $projection = app(ModuleProjectionCache::class);
        $projectionVersion = $projection->version();

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(
                'data-module-instance="pet-care-clinic:tickets"',
                false,
            )
            ->assertSee(
                'data-module-instance="pet-care-clinic:consultations"',
                false,
            );
        $this->get(route('petcare.index'))
            ->assertOk()
            ->assertSee($ticket->subject)
            ->assertSee($consultation->subject);
        $this->assertTrue(Cache::has(
            "myapes:modules:enabled:v{$projectionVersion}",
        ));

        $updated = ModuleInstallation::query()
            ->where('sub_core_key', 'pet-care-clinic')
            ->whereIn('module_key', ['tickets', 'consultations'])
            ->update([
                'enabled' => false,
                'disabled_at' => now(),
                'updated_at' => now(),
            ]);

        $this->assertSame(2, $updated);
        $this->assertSame($projectionVersion, $projection->version());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(
                'data-module-instance="pet-care-clinic:tickets"',
                false,
            )
            ->assertDontSee(
                'data-module-instance="pet-care-clinic:consultations"',
                false,
            );
        $this->get(route('petcare.index'))
            ->assertOk()
            ->assertDontSee(
                'data-module-instance="pet-care-clinic:tickets"',
                false,
            )
            ->assertDontSee(
                'data-module-instance="pet-care-clinic:consultations"',
                false,
            )
            ->assertDontSee($ticket->subject)
            ->assertDontSee($consultation->subject);
        $this->get(route('petcare.tickets.show', $ticket))->assertNotFound();
        $this->get(route('petcare.consultations.show', $consultation))
            ->assertNotFound();
        $this->assertDatabaseHas('support_tickets', ['id' => $ticket->id]);
        $this->assertDatabaseHas('pet_care_consultations', [
            'id' => $consultation->id,
        ]);
    }

    public function test_stale_disabled_projection_cannot_hide_enabled_pet_care_modules(): void
    {
        $owner = User::factory()->create();
        $projection = app(ModuleProjectionCache::class);
        $projectionVersion = $projection->version();
        $projectionKey = "myapes:modules:enabled:v{$projectionVersion}";
        ModuleInstallation::query()
            ->where('sub_core_key', 'pet-care-clinic')
            ->whereIn('module_key', ['tickets', 'consultations'])
            ->update([
                'enabled' => false,
                'disabled_at' => now(),
                'updated_at' => now(),
            ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(
                'data-module-instance="pet-care-clinic:tickets"',
                false,
            )
            ->assertDontSee(
                'data-module-instance="pet-care-clinic:consultations"',
                false,
            );
        $this->assertTrue(Cache::has($projectionKey));

        $updated = ModuleInstallation::query()
            ->where('sub_core_key', 'pet-care-clinic')
            ->whereIn('module_key', ['tickets', 'consultations'])
            ->update([
                'enabled' => true,
                'disabled_at' => null,
                'updated_at' => now(),
            ]);

        $this->assertSame(2, $updated);
        $this->assertSame($projectionVersion, $projection->version());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(
                'data-module-instance="pet-care-clinic:tickets"',
                false,
            )
            ->assertSee(
                'data-module-instance="pet-care-clinic:consultations"',
                false,
            );
        $this->get(route('petcare.index'))
            ->assertOk()
            ->assertSee(
                'data-module-instance="pet-care-clinic:tickets"',
                false,
            )
            ->assertSee(
                'data-module-instance="pet-care-clinic:consultations"',
                false,
            );
        $this->get(route('petcare.tickets.index'))->assertOk();
        $this->get(route('petcare.consultations.index'))->assertOk();
        $this->assertContains(
            'pet-care-clinic:tickets',
            Cache::get($projectionKey),
        );
        $this->assertContains(
            'pet-care-clinic:consultations',
            Cache::get($projectionKey),
        );
    }

    public function test_dashboard_reads_the_authoritative_module_projection_once_per_request(): void
    {
        $owner = User::factory()->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->actingAs($owner)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertSee(
                    'data-module-instance="pet-care-clinic:tickets"',
                    false,
                );
            $moduleQueries = collect(DB::getQueryLog())
                ->filter(static function (array $query): bool {
                    $sql = strtolower(trim($query['query']));

                    return str_starts_with($sql, 'select')
                        && str_contains($sql, 'module_plugins');
                });
        } finally {
            DB::disableQueryLog();
        }

        $this->assertCount(1, $moduleQueries);
    }

    public function test_empty_analytics_snapshots_report_no_closure_sample(): void
    {
        $registry = app(ModuleRegistry::class);

        foreach (['tickets', 'cases'] as $moduleKey) {
            $instance = $registry->instance('apes-cic', $moduleKey);
            /** @var ModuleAnalyticsProvider $provider */
            $provider = app($instance->analyticsProviderClass());
            $snapshot = $provider->snapshot(
                $instance,
                Carbon::parse('2026-08-01 00:00:00', 'Europe/London'),
                Carbon::parse('2026-08-02 00:00:00', 'Europe/London'),
                'Europe/London',
            );

            $this->assertSame(0, $snapshot->total);
            $this->assertSame(0, $snapshot->closureSampleSize);
            $this->assertNull($snapshot->medianClosureMinutes);
        }
    }

    public function test_pet_profile_activity_and_analytics_are_registered_for_both_shipped_instances(): void
    {
        $registry = app(ModuleRegistry::class);
        $shelter = $registry->instance('shelter-rescue', 'pet-profiles');
        $petCare = $registry->instance('pet-care-clinic', 'pet-profiles');

        $this->assertSame(
            PetProfileRecentActivityProvider::class,
            $shelter->recentActivityProviderClass(),
        );
        $this->assertSame(
            PetProfileAnalyticsProvider::class,
            $shelter->analyticsProviderClass(),
        );
        $this->assertSame(
            PetProfileRecentActivityProvider::class,
            $petCare->recentActivityProviderClass(),
        );
        $this->assertSame(
            PetProfileAnalyticsProvider::class,
            $petCare->analyticsProviderClass(),
        );
    }

    public function test_shelter_pet_profile_activity_is_exactly_visible_latest_and_route_scoped(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $instance = app(ModuleRegistry::class)
            ->instance('shelter-rescue', 'pet-profiles');
        $this->petProfileFor($owner, [
            'name' => 'Older visible Shelter profile',
            'updated_at' => Carbon::parse('2026-08-01 10:00:00', 'UTC'),
        ]);
        $latest = $this->petProfileFor($owner, [
            'name' => 'Latest visible Shelter profile',
            'updated_at' => Carbon::parse('2026-08-02 10:00:00', 'UTC'),
        ]);
        $this->petProfileFor($other, [
            'name' => 'Other owner Shelter profile',
            'updated_at' => Carbon::parse('2026-08-03 10:00:00', 'UTC'),
        ]);
        $this->petProfileFor($owner, [
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Pet Care profile',
            'updated_at' => Carbon::parse('2026-08-04 10:00:00', 'UTC'),
        ]);

        $this->actingAs($owner);
        /** @var ModuleRecentActivityProvider $provider */
        $providerClass = $instance->recentActivityProviderClass();
        $this->assertNotNull($providerClass);
        $provider = app($providerClass);
        $activity = $provider->recent($instance, $owner, 1);

        $this->assertCount(1, $activity);
        $this->assertSame('shelter-rescue:pet-profiles', $activity[0]->instanceKey);
        $this->assertSame('pet-profiles', $activity[0]->moduleKey);
        $this->assertSame('Pet profile', $activity[0]->label);
        $this->assertSame('Latest visible Shelter profile', $activity[0]->title);
        $this->assertSame('active', $activity[0]->status);
        $this->assertNull($activity[0]->priority);
        $this->assertSame('shelter.pets.show', $activity[0]->routeName);
        $this->assertSame($latest->id, $activity[0]->recordId);
        $this->assertTrue($latest->updated_at->equalTo($activity[0]->updatedAt));
    }

    public function test_shelter_pet_profile_analytics_use_half_open_range_and_timezone_buckets(): void
    {
        $owner = User::factory()->create();
        $instance = app(ModuleRegistry::class)
            ->instance('shelter-rescue', 'pet-profiles');
        $this->petProfileFor($owner, [
            'name' => 'First in range',
            'created_at' => Carbon::parse('2026-07-31 23:30:00', 'UTC'),
        ]);
        $this->petProfileFor($owner, [
            'name' => 'Second in range',
            'created_at' => Carbon::parse('2026-08-01 22:30:00', 'UTC'),
        ]);
        $this->petProfileFor($owner, [
            'name' => 'Exclusive boundary',
            'created_at' => Carbon::parse('2026-08-01 23:00:00', 'UTC'),
        ]);
        $this->petProfileFor($owner, [
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Foreign Pet Care profile',
            'created_at' => Carbon::parse('2026-08-01 12:00:00', 'UTC'),
        ]);

        /** @var ModuleAnalyticsProvider $provider */
        $providerClass = $instance->analyticsProviderClass();
        $this->assertNotNull($providerClass);
        $provider = app($providerClass);
        $snapshot = $provider->snapshot(
            $instance,
            Carbon::parse('2026-08-01 00:00:00', 'Europe/London'),
            Carbon::parse('2026-08-02 00:00:00', 'Europe/London'),
            'Europe/London',
        );

        $this->assertSame('shelter-rescue:pet-profiles', $snapshot->instanceKey);
        $this->assertSame(2, $snapshot->total);
        $this->assertSame(0, $snapshot->open);
        $this->assertSame(0, $snapshot->highOrUrgent);
        $this->assertSame(0, $snapshot->unassigned);
        $this->assertSame(['2026-08-01' => 2], $snapshot->createdPerDay);
        $this->assertSame([], $snapshot->closedPerDay);
        $this->assertNull($snapshot->medianClosureMinutes);
        $this->assertSame(0, $snapshot->closureSampleSize);
    }

    public function test_pet_care_pet_profile_activity_uses_exact_instance_visibility_route_and_registry_labels(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $instance = app(ModuleRegistry::class)
            ->instance('pet-care-clinic', 'pet-profiles');
        $latest = $this->petProfileFor($owner, [
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Latest visible Clinic profile',
            'updated_at' => Carbon::parse('2026-08-03 10:00:00', 'UTC'),
        ]);
        $this->petProfileFor($other, [
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Other owner Clinic profile',
            'updated_at' => Carbon::parse('2026-08-04 10:00:00', 'UTC'),
        ]);
        $this->petProfileFor($owner, [
            'service_domain' => PetProfile::DOMAIN_SHELTER,
            'name' => 'Foreign Shelter profile',
            'updated_at' => Carbon::parse('2026-08-05 10:00:00', 'UTC'),
        ]);

        $this->actingAs($owner);
        /** @var ModuleRecentActivityProvider $provider */
        $providerClass = $instance->recentActivityProviderClass();
        $this->assertNotNull($providerClass);
        $provider = app($providerClass);
        $activity = $provider->recent($instance, $owner, 5);
        $summaryProvider = app($instance->summaryProviderClass());
        $summary = $summaryProvider->summarize($instance, $owner);

        $this->assertSame('APES Pet Care Clinic', $instance->subCore->name);
        $this->assertSame('Pet profiles', $summary->label);
        $this->assertSame('pet-care-clinic', $summary->subCoreKey);
        $this->assertSame('APES Pet Care Clinic', $summary->subCoreName);
        $this->assertSame(
            'Pet Profiles',
            $instance->module->navigation['pet-care-clinic']->label,
        );
        $this->assertCount(1, $activity);
        $this->assertSame('pet-care-clinic:pet-profiles', $activity[0]->instanceKey);
        $this->assertSame('pet-profiles', $activity[0]->moduleKey);
        $this->assertSame('Pet profile', $activity[0]->label);
        $this->assertSame('Latest visible Clinic profile', $activity[0]->title);
        $this->assertSame('active', $activity[0]->status);
        $this->assertNull($activity[0]->priority);
        $this->assertSame('petcare.pets.show', $activity[0]->routeName);
        $this->assertSame($latest->id, $activity[0]->recordId);
        $this->assertTrue($latest->updated_at->equalTo($activity[0]->updatedAt));
    }

    public function test_pet_care_pet_profile_activity_view_all_staff_sees_all_pet_care_owners_but_no_shelter_records(): void
    {
        $firstOwner = User::factory()->create();
        $secondOwner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $instance = app(ModuleRegistry::class)
            ->instance('pet-care-clinic', 'pet-profiles');
        $first = $this->petProfileFor($firstOwner, [
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'First staff-visible Clinic profile',
            'updated_at' => Carbon::parse('2026-08-02 10:00:00', 'UTC'),
        ]);
        $second = $this->petProfileFor($secondOwner, [
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Second staff-visible Clinic profile',
            'updated_at' => Carbon::parse('2026-08-03 10:00:00', 'UTC'),
        ]);
        $this->petProfileFor($firstOwner, [
            'service_domain' => PetProfile::DOMAIN_SHELTER,
            'name' => 'Staff-hidden Shelter profile',
            'updated_at' => Carbon::parse('2026-08-04 10:00:00', 'UTC'),
        ]);

        $this->actingAs($staff);
        $this->assertTrue($staff->can('pet-care-clinic.pet-profiles.view-all'));
        /** @var ModuleRecentActivityProvider $provider */
        $providerClass = $instance->recentActivityProviderClass();
        $this->assertNotNull($providerClass);
        $activity = app($providerClass)->recent($instance, $staff, 5);

        $this->assertSame(
            [$second->id, $first->id],
            array_column($activity, 'recordId'),
        );
        $this->assertSame(
            [
                'Second staff-visible Clinic profile',
                'First staff-visible Clinic profile',
            ],
            array_column($activity, 'title'),
        );
        $this->assertSame(
            ['petcare.pets.show', 'petcare.pets.show'],
            array_column($activity, 'routeName'),
        );
    }

    public function test_pet_care_pet_profile_analytics_use_half_open_range_timezone_and_domain_scope(): void
    {
        $owner = User::factory()->create();
        $instance = app(ModuleRegistry::class)
            ->instance('pet-care-clinic', 'pet-profiles');
        $this->petProfileFor($owner, [
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'First Clinic profile in range',
            'created_at' => Carbon::parse('2026-07-31 23:30:00', 'UTC'),
        ]);
        $this->petProfileFor($owner, [
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Second Clinic profile in range',
            'created_at' => Carbon::parse('2026-08-01 22:30:00', 'UTC'),
        ]);
        $this->petProfileFor($owner, [
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Clinic profile at exclusive boundary',
            'created_at' => Carbon::parse('2026-08-01 23:00:00', 'UTC'),
        ]);
        $this->petProfileFor($owner, [
            'service_domain' => PetProfile::DOMAIN_SHELTER,
            'name' => 'Foreign Shelter profile in range',
            'created_at' => Carbon::parse('2026-08-01 12:00:00', 'UTC'),
        ]);

        /** @var ModuleAnalyticsProvider $provider */
        $providerClass = $instance->analyticsProviderClass();
        $this->assertNotNull($providerClass);
        $provider = app($providerClass);
        $snapshot = $provider->snapshot(
            $instance,
            Carbon::parse('2026-08-01 00:00:00', 'Europe/London'),
            Carbon::parse('2026-08-02 00:00:00', 'Europe/London'),
            'Europe/London',
        );

        $this->assertSame('pet-care-clinic:pet-profiles', $snapshot->instanceKey);
        $this->assertSame(2, $snapshot->total);
        $this->assertSame(0, $snapshot->open);
        $this->assertSame(0, $snapshot->highOrUrgent);
        $this->assertSame(0, $snapshot->unassigned);
        $this->assertSame(['2026-08-01' => 2], $snapshot->createdPerDay);
        $this->assertSame([], $snapshot->closedPerDay);
        $this->assertNull($snapshot->medianClosureMinutes);
        $this->assertSame(0, $snapshot->closureSampleSize);
    }

    private function caseFor(User $owner, array $attributes = []): ShelterCase
    {
        $timestamps = array_intersect_key(
            $attributes,
            array_flip(['created_at', 'updated_at']),
        );
        $case = ShelterCase::create(array_merge([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'category' => 'general',
            'priority' => 'medium',
            'status' => 'open',
            'title' => 'General APES CIC case',
            'details' => null,
            'opened_at' => now(),
        ], $attributes));

        if ($timestamps !== []) {
            $case->timestamps = false;
            $case->forceFill($timestamps)->saveQuietly();
            $case->timestamps = true;
        }

        return $case->fresh();
    }

    private function petProfileFor(User $owner, array $attributes = []): PetProfile
    {
        $timestamps = array_intersect_key(
            $attributes,
            array_flip(['created_at', 'updated_at']),
        );
        $pet = PetProfile::query()->create(array_merge([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_SHELTER,
            'name' => 'Shelter profile',
            'species' => 'dog',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ], $attributes));

        if ($timestamps !== []) {
            $pet->timestamps = false;
            $pet->forceFill($timestamps)->saveQuietly();
            $pet->timestamps = true;
        }

        return $pet->fresh();
    }

    private function ticketFor(User $owner, array $attributes = []): SupportTicket
    {
        $timestamps = array_intersect_key(
            $attributes,
            array_flip(['created_at', 'updated_at']),
        );
        $ticket = SupportTicket::create(array_merge([
            'sub_core_key' => SupportTicket::SUB_CORE_APES_CIC,
            'user_id' => $owner->id,
            'service_area' => 'operations',
            'subject' => 'General APES CIC ticket',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Ticket analytics fixture.',
        ], $attributes));

        if ($timestamps !== []) {
            $ticket->timestamps = false;
            $ticket->forceFill($timestamps)->saveQuietly();
            $ticket->timestamps = true;
        }

        return $ticket->fresh();
    }
}

final class OverrideSummaryProvider implements ModuleAggregateSummaryProvider
{
    public function summarize(
        ModuleInstanceDefinition $instance,
        User $user,
    ): ModuleSummary {
        return new ModuleSummary(
            $instance->key(),
            'Override',
            0,
            0,
            'home',
            'home',
            'home',
            '',
            $instance->subCore->key,
            $instance->subCore->name,
        );
    }
}

final class OverrideRecentActivityProvider implements ModuleRecentActivityProvider
{
    public function recent(
        ModuleInstanceDefinition $instance,
        User $user,
        int $limit = 5,
    ): array {
        return [];
    }
}

final class OverrideAnalyticsProvider implements ModuleAnalyticsProvider
{
    public function snapshot(
        ModuleInstanceDefinition $instance,
        DateTimeInterface $from,
        DateTimeInterface $to,
        string $timezone,
    ): ModuleAnalyticsSnapshot {
        return new ModuleAnalyticsSnapshot($instance->key(), 0, 0, 0, 0, [], [], null, 0);
    }
}

final class OverrideAttentionProvider implements ModuleAttentionProvider
{
    public function attention(
        ModuleInstanceDefinition $instance,
        User $user,
        int $limit = 6,
    ): array {
        return [];
    }
}
