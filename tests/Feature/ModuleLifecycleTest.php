<?php

namespace Tests\Feature;

use App\Contracts\ModuleActiveRecordDetector;
use App\Contracts\ModuleLifecycleManager;
use App\Contracts\ModuleRegistry;
use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Exceptions\ModuleLifecycleException;
use App\Services\AuthorizationMetadataSynchronizer;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use App\Services\ModuleInstanceLock;
use App\Services\ModuleProjectionCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\Tickets\Dashboard\SupportTicketActiveRecordDetector;
use Plugins\Tickets\Models\SupportTicket;
use RuntimeException;
use Tests\TestCase;

class ModuleLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private ModuleLifecycleManager $lifecycle;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleInstallationSynchronizer::class)->synchronize();
        $this->superAdmin = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $this->actingAs($this->superAdmin);
        $this->lifecycle = app(ModuleLifecycleManager::class);
    }

    public function test_super_admin_can_disable_and_enable_an_inactive_module_without_losing_data(): void
    {
        $installation = $this->installation('apes-cic', 'tickets');

        $disabled = $this->lifecycle->disable(
            $this->superAdmin,
            'apes-cic',
            'tickets',
            (int) $installation->lock_version,
        );

        $this->assertFalse($disabled->enabled);
        $this->assertSame($this->superAdmin->id, $disabled->disabled_by);
        $this->assertNotNull($disabled->disabled_at);

        $enabled = $this->lifecycle->enable(
            $this->superAdmin,
            'apes-cic',
            'tickets',
            (int) $disabled->lock_version,
        );

        $this->assertTrue($enabled->enabled);
        $this->assertSame($this->superAdmin->id, $enabled->enabled_by);
        $this->assertNull($enabled->disabled_at);
        $this->assertNull($enabled->disabled_by);
        $this->assertSame(2, AuditLog::query()
            ->where('event', 'module.lifecycle_succeeded')
            ->count());
    }

    public function test_only_an_eligible_current_administrator_can_mutate_module_state(): void
    {
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $this->actingAs($administrator);
        $installation = $this->installation('apes-cic', 'tickets');

        try {
            $this->lifecycle->disable(
                $administrator,
                'apes-cic',
                'tickets',
                (int) $installation->lock_version,
            );
            $this->fail('A staff member mutated module state.');
        } catch (ModuleLifecycleException $exception) {
            $this->assertSame('forbidden', $exception->reason);
        }

        $this->assertTrue($installation->fresh()->enabled);
        $audit = AuditLog::query()
            ->where('event', 'module.lifecycle_refused')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('forbidden', $audit->context['reason']);
        $this->assertSame($administrator->id, $audit->user_id);
        $this->assertArrayNotHasKey('email', $audit->context);
        $this->assertArrayNotHasKey('name', $audit->context);
    }

    public function test_install_rejects_duplicate_and_incompatible_instances(): void
    {
        foreach ([
            ['apes-cic', 'tickets', 'duplicate_installation'],
            ['shelter-rescue', 'tickets', 'duplicate_installation'],
            ['pet-care-clinic', 'tickets', 'duplicate_installation'],
            ['apes-cic', 'pet-profiles', 'incompatible'],
        ] as [$subCore, $module, $reason]) {
            try {
                $this->lifecycle->install(
                    $this->superAdmin,
                    $subCore,
                    $module,
                );
                $this->fail("Unexpected install succeeded for {$subCore}:{$module}.");
            } catch (ModuleLifecycleException $exception) {
                $this->assertSame($reason, $exception->reason);
            }
        }

        $this->assertDatabaseCount('module_plugins', 9);
    }

    public function test_install_recreates_an_available_shipped_instance_with_actor_provenance(): void
    {
        $this->installation('apes-cic', 'tickets')->delete();

        $installation = $this->lifecycle->install(
            $this->superAdmin,
            'apes-cic',
            'tickets',
        );

        $this->assertTrue($installation->enabled);
        $this->assertSame($this->superAdmin->id, $installation->installed_by);
        $this->assertSame($this->superAdmin->id, $installation->enabled_by);
        $this->assertNotNull($installation->installed_at);
        $this->assertNotNull($installation->enabled_at);
    }

    public function test_failed_permission_provisioning_rolls_back_installation(): void
    {
        $this->installation('apes-cic', 'tickets')->delete();
        $this->mock(
            AuthorizationMetadataSynchronizer::class,
            function ($mock): void {
                $mock->shouldReceive('synchronize')
                    ->once()
                    ->andThrow(new RuntimeException('unsafe provisioning detail'));
            },
        );

        try {
            app(ModuleLifecycleManager::class)->install(
                $this->superAdmin,
                'apes-cic',
                'tickets',
            );
            $this->fail('A failed permission provision left an installation.');
        } catch (ModuleLifecycleException $exception) {
            $this->assertSame('transition_failed', $exception->reason);
        }

        $this->assertDatabaseMissing('module_plugins', [
            'sub_core_key' => 'apes-cic',
            'module_key' => 'tickets',
        ]);
        $audit = AuditLog::query()
            ->where('event', 'module.lifecycle_rolled_back')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('transition_failed', $audit->context['reason']);
        $this->assertStringNotContainsString(
            'unsafe provisioning detail',
            json_encode($audit->context, JSON_THROW_ON_ERROR),
        );
    }

    public function test_enabled_dependents_prevent_disabling_pet_profiles(): void
    {
        foreach ([
            ['shelter-rescue', 'pet-profiles'],
            ['pet-care-clinic', 'pet-profiles'],
        ] as [$subCore, $module]) {
            $installation = $this->installation($subCore, $module);

            try {
                $this->lifecycle->disable(
                    $this->superAdmin,
                    $subCore,
                    $module,
                    (int) $installation->lock_version,
                );
                $this->fail('A dependency root was disabled while in use.');
            } catch (ModuleLifecycleException $exception) {
                $this->assertSame('enabled_dependent', $exception->reason);
            }
        }
    }

    public function test_active_records_prevent_disablement_and_are_never_deleted(): void
    {
        $owner = User::factory()->create();
        $ticket = SupportTicket::query()->create([
            'user_id' => $owner->id,
            'assigned_to' => null,
            'service_area' => 'general',
            'subject' => 'Retain this record',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Lifecycle checks must preserve module data.',
            'closed_at' => null,
        ]);
        $installation = $this->installation('apes-cic', 'tickets');

        try {
            $this->lifecycle->disable(
                $this->superAdmin,
                'apes-cic',
                'tickets',
                (int) $installation->lock_version,
            );
            $this->fail('A module with active records was disabled.');
        } catch (ModuleLifecycleException $exception) {
            $this->assertSame('active_records', $exception->reason);
        }

        $this->assertTrue($installation->fresh()->enabled);
        $this->assertDatabaseHas('support_tickets', ['id' => $ticket->id]);
        $audit = AuditLog::query()
            ->where('event', 'module.lifecycle_refused')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(1, $audit->context['active_record_count']);
    }

    public function test_open_petcare_ticket_blocks_only_its_own_module_disablement(): void
    {
        $owner = User::factory()->create();
        $ticket = SupportTicket::query()->create([
            'sub_core_key' => 'pet-care-clinic',
            'user_id' => $owner->id,
            'service_area' => 'appointment',
            'subject' => 'Retain this Pet Care ticket',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Only Pet Care Tickets should be blocked.',
        ]);

        $petCareInstallation = $this->installation('pet-care-clinic', 'tickets');
        try {
            $this->lifecycle->disable(
                $this->superAdmin,
                'pet-care-clinic',
                'tickets',
                (int) $petCareInstallation->lock_version,
            );
            $this->fail('An open Pet Care ticket did not block disablement.');
        } catch (ModuleLifecycleException $exception) {
            $this->assertSame('active_records', $exception->reason);
        }

        $apesInstallation = $this->installation('apes-cic', 'tickets');
        $disabled = $this->lifecycle->disable(
            $this->superAdmin,
            'apes-cic',
            'tickets',
            (int) $apesInstallation->lock_version,
        );
        $this->assertFalse($disabled->enabled);
        $this->assertDatabaseHas('support_tickets', ['id' => $ticket->id]);
    }

    public function test_an_active_shelter_ticket_blocks_only_the_shelter_ticket_installation(): void
    {
        $owner = User::factory()->create();
        SupportTicket::create([
            'sub_core_key' => 'shelter-rescue',
            'user_id' => $owner->id,
            'service_area' => 'rescue',
            'subject' => 'Active Shelter Ticket',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Only the matching installation is protected.',
        ]);
        $shelter = $this->installation('shelter-rescue', 'tickets');
        $apes = $this->installation('apes-cic', 'tickets');

        $disabledApes = $this->lifecycle->disable(
            $this->superAdmin,
            'apes-cic',
            'tickets',
            (int) $apes->lock_version,
        );
        $this->assertFalse($disabledApes->enabled);

        try {
            $this->lifecycle->disable(
                $this->superAdmin,
                'shelter-rescue',
                'tickets',
                (int) $shelter->lock_version,
            );
            $this->fail('An active Shelter Ticket did not block its installation.');
        } catch (ModuleLifecycleException $exception) {
            $this->assertSame('active_records', $exception->reason);
        }

        $this->assertTrue($shelter->fresh()->enabled);
    }

    public function test_each_shipped_module_detector_counts_only_its_active_domain_records(): void
    {
        $owner = User::factory()->create();
        $shelterPet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_SHELTER,
            'name' => 'Shelter animal',
            'species' => 'bird',
        ]);
        $petCarePet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Clinic animal',
            'species' => 'reptile',
        ]);
        SupportTicket::query()->create([
            'user_id' => $owner->id,
            'service_area' => 'it',
            'subject' => 'Open ticket',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Active ticket.',
        ]);
        SupportTicket::query()->create([
            'sub_core_key' => 'shelter-rescue',
            'user_id' => $owner->id,
            'service_area' => 'rescue',
            'subject' => 'Open Shelter ticket',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Active Shelter ticket.',
        ]);
        SupportTicket::query()->create([
            'sub_core_key' => 'pet-care-clinic',
            'user_id' => $owner->id,
            'service_area' => 'appointment',
            'subject' => 'Open Pet Care ticket',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Active Pet Care ticket.',
        ]);
        ShelterCase::query()->create([
            'pet_profile_id' => $shelterPet->id,
            'user_id' => $owner->id,
            'case_type' => 'rescue',
            'status' => 'open',
            'title' => 'Open case',
        ]);
        ShelterCase::query()->create([
            'sub_core_key' => ShelterCase::SUB_CORE_APES_CIC,
            'pet_profile_id' => null,
            'user_id' => $owner->id,
            'case_type' => null,
            'category' => 'general',
            'priority' => 'medium',
            'status' => 'open',
            'title' => 'Open APES CIC case',
            'opened_at' => now(),
        ]);
        PetCareConsultation::query()->create([
            'pet_profile_id' => $petCarePet->id,
            'user_id' => $owner->id,
            'subject' => 'Open consultation',
            'status' => 'open',
        ]);

        $registry = app(ModuleRegistry::class);
        foreach (array_keys($registry->shippedInstances()) as $key) {
            [$subCore, $module] = explode(':', $key, 2);
            $instance = $registry->instance($subCore, $module);
            /** @var ModuleActiveRecordDetector $detector */
            $detector = app($instance->module->activeRecordDetector);

            // Recruitment has no persisted records until Wave 1 CRUD.
            $expected = $module === 'recruitment' ? 0 : 1;
            $this->assertSame($expected, $detector->count($instance), $key);
        }
    }

    public function test_resolved_apes_case_is_inactive_while_shelter_in_review_case_remains_active(): void
    {
        $owner = User::factory()->create();
        $pet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_SHELTER,
            'name' => 'Lifecycle semantics pet',
            'species' => 'bird',
        ]);
        $resolvedApesCase = ShelterCase::query()->create([
            'sub_core_key' => ShelterCase::SUB_CORE_APES_CIC,
            'pet_profile_id' => null,
            'user_id' => $owner->id,
            'case_type' => null,
            'category' => 'general',
            'priority' => 'medium',
            'status' => 'resolved',
            'title' => 'Resolved APES CIC lifecycle case',
            'opened_at' => now()->subDay(),
            'resolved_at' => now(),
            'closed_at' => null,
        ]);
        ShelterCase::query()->create([
            'sub_core_key' => ShelterCase::SUB_CORE_SHELTER_RESCUE,
            'pet_profile_id' => $pet->id,
            'user_id' => $owner->id,
            'case_type' => 'rescue',
            'category' => null,
            'priority' => 'medium',
            'status' => 'in_review',
            'title' => 'Shelter case still under review',
            'closed_at' => null,
        ]);
        $registry = app(ModuleRegistry::class);
        $apesInstance = $registry->instance('apes-cic', 'cases');
        $shelterInstance = $registry->instance('shelter-rescue', 'cases');
        /** @var ModuleActiveRecordDetector $detector */
        $detector = app($apesInstance->module->activeRecordDetector);

        $this->assertSame(0, $detector->count($apesInstance));
        $this->assertSame(1, $detector->count($shelterInstance));

        $apesInstallation = $this->installation('apes-cic', 'cases');
        $disabled = $this->lifecycle->disable(
            $this->superAdmin,
            'apes-cic',
            'cases',
            (int) $apesInstallation->lock_version,
        );
        $this->assertFalse($disabled->enabled);
        $this->assertDatabaseHas('shelter_cases', ['id' => $resolvedApesCase->id]);

        $shelterInstallation = $this->installation('shelter-rescue', 'cases');
        try {
            $this->lifecycle->disable(
                $this->superAdmin,
                'shelter-rescue',
                'cases',
                (int) $shelterInstallation->lock_version,
            );
            $this->fail('A Shelter module with an in-review case was disabled.');
        } catch (ModuleLifecycleException $exception) {
            $this->assertSame('active_records', $exception->reason);
        }
        $this->assertTrue($shelterInstallation->fresh()->enabled);
    }

    public function test_stale_transitions_are_rejected_without_changing_state(): void
    {
        $installation = $this->installation('apes-cic', 'tickets');

        try {
            $this->lifecycle->disable(
                $this->superAdmin,
                'apes-cic',
                'tickets',
                (int) $installation->lock_version + 1,
            );
            $this->fail('A stale module transition was accepted.');
        } catch (ModuleLifecycleException $exception) {
            $this->assertSame('stale_transition', $exception->reason);
        }

        $this->assertTrue($installation->fresh()->enabled);
    }

    public function test_transition_versions_advance_even_when_multiple_changes_share_one_second(): void
    {
        Carbon::setTestNow('2026-08-06 12:00:00');
        try {
            $installation = $this->installation('apes-cic', 'tickets');
            $initialVersion = (int) $installation->lock_version;
            $disabled = $this->lifecycle->disable(
                $this->superAdmin,
                'apes-cic',
                'tickets',
                $initialVersion,
            );
            $enabled = $this->lifecycle->enable(
                $this->superAdmin,
                'apes-cic',
                'tickets',
                (int) $disabled->lock_version,
            );

            $this->assertSame($initialVersion + 2, $enabled->lock_version);

            try {
                $this->lifecycle->disable(
                    $this->superAdmin,
                    'apes-cic',
                    'tickets',
                    $initialVersion,
                );
                $this->fail('A same-second stale transition token was reused.');
            } catch (ModuleLifecycleException $exception) {
                $this->assertSame('stale_transition', $exception->reason);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_lock_refusals_are_audited_without_sensitive_actor_data(): void
    {
        $this->mock(ModuleInstanceLock::class, function ($mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andThrow(new ModuleLifecycleException('instance_busy'));
        });
        $installation = $this->installation('apes-cic', 'tickets');

        try {
            app(ModuleLifecycleManager::class)->disable(
                $this->superAdmin,
                'apes-cic',
                'tickets',
                (int) $installation->lock_version,
            );
            $this->fail('A busy module lock was not reported.');
        } catch (ModuleLifecycleException $exception) {
            $this->assertSame('instance_busy', $exception->reason);
        }

        $audit = AuditLog::query()
            ->where('event', 'module.lifecycle_refused')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('instance_busy', $audit->context['reason']);
        $this->assertArrayNotHasKey('email', $audit->context);
        $this->assertArrayNotHasKey('name', $audit->context);
    }

    public function test_unexpected_detector_failures_roll_back_and_are_reason_coded(): void
    {
        $this->mock(
            SupportTicketActiveRecordDetector::class,
            function ($mock): void {
                $mock->shouldReceive('count')
                    ->once()
                    ->andThrow(new RuntimeException('unsafe details'));
            },
        );
        $installation = $this->installation('apes-cic', 'tickets');

        try {
            $this->lifecycle->disable(
                $this->superAdmin,
                'apes-cic',
                'tickets',
                (int) $installation->lock_version,
            );
            $this->fail('A failed detector allowed a state transition.');
        } catch (ModuleLifecycleException $exception) {
            $this->assertSame('transition_failed', $exception->reason);
        }

        $this->assertTrue($installation->fresh()->enabled);
        $audit = AuditLog::query()
            ->where('event', 'module.lifecycle_rolled_back')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('transition_failed', $audit->context['reason']);
        $this->assertStringNotContainsString(
            'unsafe details',
            json_encode($audit->context, JSON_THROW_ON_ERROR),
        );
    }

    public function test_only_committed_transitions_invalidate_projection_cache(): void
    {
        $cache = app(ModuleProjectionCache::class);
        $before = $cache->version();
        $installation = $this->installation('apes-cic', 'tickets');

        try {
            $this->lifecycle->disable(
                $this->superAdmin,
                'apes-cic',
                'tickets',
                0,
            );
        } catch (ModuleLifecycleException) {
            // The failed transition must not publish a new projection version.
        }

        $this->assertSame($before, $cache->version());

        $this->lifecycle->disable(
            $this->superAdmin,
            'apes-cic',
            'tickets',
            (int) $installation->lock_version,
        );

        $this->assertSame($before + 1, $cache->version());
    }

    public function test_a_projection_cache_failure_does_not_turn_a_committed_transition_into_an_error(): void
    {
        $this->mock(ModuleProjectionCache::class, function ($mock): void {
            $mock->shouldReceive('invalidate')
                ->once()
                ->andThrow(new RuntimeException('unsafe cache details'));
        });
        $installation = $this->installation('apes-cic', 'tickets');

        $disabled = app(ModuleLifecycleManager::class)->disable(
            $this->superAdmin,
            'apes-cic',
            'tickets',
            (int) $installation->lock_version,
        );

        $this->assertFalse($disabled->enabled);
        $audit = AuditLog::query()
            ->where('event', 'module.projection_invalidation_failed')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('cache_unavailable', $audit->context['reason']);
        $this->assertStringNotContainsString(
            'unsafe cache details',
            json_encode($audit->context, JSON_THROW_ON_ERROR),
        );
    }

    public function test_projection_cache_uses_an_atomic_version_increment(): void
    {
        $source = file_get_contents(
            app_path('Services/ModuleProjectionCache.php'),
        );

        $this->assertIsString($source);
        $this->assertStringContainsString('Cache::increment(', $source);
        $this->assertStringNotContainsString(
            'Cache::forever(self::VERSION_KEY, $this->version() + 1)',
            $source,
        );
    }

    private function installation(
        string $subCore,
        string $module,
    ): ModuleInstallation {
        return ModuleInstallation::query()
            ->where('sub_core_key', $subCore)
            ->where('module_key', $module)
            ->firstOrFail();
    }
}
