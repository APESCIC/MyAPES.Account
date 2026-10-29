<?php

namespace Tests\Feature;

use App\Contracts\ModuleActiveRecordDetector;
use App\Contracts\ModuleAnalyticsProvider;
use App\Contracts\ModuleAttentionProvider;
use App\Contracts\ModuleLifecycleManager;
use App\Contracts\ModuleRecentActivityProvider;
use App\Contracts\ModuleRegistry;
use App\Core\Accounts\AuditLog;
use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Exceptions\ModuleLifecycleException;
use App\Services\AuthorizationProfile;
use App\Services\AuthorizationRoleMaterializer;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\Consultations\Notifications\ConsultationUpdatedNotification;
use Plugins\PetProfiles\Models\PetProfile;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PetCareConsultationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_create_authorizes_exact_permission_before_pet_resolution_and_preserves_identity_context(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $staffRecipient = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $pet = $this->petFor($owner, PetProfile::DOMAIN_PETCARE, 'Clinic creation pet');

        $response = $this->actingAs($owner)->post(route('petcare.consultations.store'), [
            'pet_profile_id' => $pet->id,
            'subject' => 'Stable consultation identity',
            'notes' => 'Private creation notes.',
            'scheduled_for' => '2026-08-20 14:30:00',
        ]);

        $consultation = PetCareConsultation::query()
            ->where('subject', 'Stable consultation identity')
            ->firstOrFail();
        $response->assertRedirect(route('petcare.consultations.show', $consultation));
        $this->assertSame($owner->id, $consultation->user_id);
        $this->assertSame($pet->id, $consultation->pet_profile_id);
        $this->assertSame('open', $consultation->status);
        $this->assertNull($consultation->assigned_to);
        $this->assertNull($consultation->closed_at);
        $this->assertSame(
            route('petcare.consultations.show', $consultation),
            route('petcare.consultations.show', $consultation->id),
        );

        $audit = AuditLog::query()
            ->where('event', 'petcare.consultation.created')
            ->where('auditable_type', 'consultation')
            ->where('auditable_id', $consultation->id)
            ->firstOrFail();
        $this->assertArrayHasKey('sub_core_key', $audit->context);
        $this->assertArrayHasKey('module_key', $audit->context);
        $this->assertSame('pet-care-clinic', $audit->context['sub_core_key']);
        $this->assertSame('consultations', $audit->context['module_key']);
        $this->assertStringNotContainsString(
            'Private creation notes.',
            json_encode($audit->context, JSON_THROW_ON_ERROR),
        );
        Notification::assertNotSentTo($owner, ConsultationUpdatedNotification::class);
        Notification::assertSentTo(
            $staffRecipient,
            ConsultationUpdatedNotification::class,
            function (ConsultationUpdatedNotification $notification) use ($consultation, $staffRecipient): bool {
                $data = $notification->toArray($staffRecipient);
                $mail = $notification->toMail($staffRecipient);

                return $data['event'] === 'created'
                    && $mail->subject === "APES Pet Care Clinic consultation #{$consultation->id} created"
                    && ! str_contains(
                        json_encode($data, JSON_THROW_ON_ERROR),
                        'Private creation notes.',
                    );
            },
        );

        $shelterPet = $this->petFor($owner, PetProfile::DOMAIN_SHELTER, 'Shelter create boundary');
        $otherPet = $this->petFor(
            User::factory()->create(),
            PetProfile::DOMAIN_PETCARE,
            'Invisible create boundary',
        );
        foreach ([$shelterPet, $otherPet] as $ineligiblePet) {
            $this->actingAs($owner)
                ->post(route('petcare.consultations.store'), [
                    'pet_profile_id' => $ineligiblePet->id,
                    'subject' => 'Forbidden pet lookup consultation',
                ])
                ->assertNotFound();
        }
        $this->assertDatabaseMissing('pet_care_consultations', [
            'subject' => 'Forbidden pet lookup consultation',
        ]);

        $this->removeRolePermissions(
            AuthorizationProfile::ROLE_SERVICE_USER,
            ['pet-care-clinic.consultations.create'],
        );
        $owner = $owner->fresh();
        $this->actingAs($owner)
            ->get(route('petcare.consultations.index'))
            ->assertOk()
            ->assertDontSee('action="'.route('petcare.consultations.store').'"', false);
        $this->post(route('petcare.consultations.store'), [
            'pet_profile_id' => 999999,
            'subject' => '',
        ])->assertForbidden();
        $this->assertDatabaseCount('pet_care_consultations', 1);
    }

    public function test_lists_and_routes_require_exact_visibility_and_linked_pet_care_domain(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $ownedPet = $this->petFor($owner, PetProfile::DOMAIN_PETCARE, 'Owned Clinic pet');
        $otherPet = $this->petFor($otherOwner, PetProfile::DOMAIN_PETCARE, 'Other Clinic pet');
        $shelterPet = $this->petFor($owner, PetProfile::DOMAIN_SHELTER, 'Malformed Shelter pet');
        $owned = $this->consultationFor($owner, $ownedPet, 'Owned Clinic consultation');
        $other = $this->consultationFor($otherOwner, $otherPet, 'Other Clinic consultation');
        $malformed = $this->consultationFor($owner, $shelterPet, 'Malformed Shelter consultation');

        $this->actingAs($owner)
            ->get(route('petcare.consultations.index'))
            ->assertOk()
            ->assertSee('service-label apes-petcare">APES Pet Care Clinic</span>', false)
            ->assertSee($owned->subject)
            ->assertDontSee($other->subject)
            ->assertDontSee($malformed->subject);
        $this->get(route('petcare.consultations.show', $owned))
            ->assertOk()
            ->assertSee('service-label apes-petcare">APES Pet Care Clinic</span>', false);
        $this->get(route('petcare.consultations.show', $other))->assertForbidden();

        $this->actingAs($staff)
            ->get(route('petcare.consultations.index'))
            ->assertOk()
            ->assertSee($owned->subject)
            ->assertSee($other->subject)
            ->assertDontSee($malformed->subject);
        $this->put(route('petcare.consultations.update', $other), [
            'notes' => 'Exact staff update-all notes.',
        ])->assertRedirect(route('petcare.consultations.show', $other));
        $this->assertSame('Exact staff update-all notes.', $other->fresh()->notes);

        $this->removeRolePermissions(AuthorizationProfile::ROLE_STAFF, [
            'shelter-rescue.pet-profiles.view-own',
            'shelter-rescue.pet-profiles.view-all',
            'shelter-rescue.pet-profiles.update-own',
            'shelter-rescue.pet-profiles.update-all',
        ]);
        $staff = $staff->fresh();
        $this->get(route('petcare.consultations.show', $malformed))
            ->assertNotFound();
        $this->put(route('petcare.consultations.update', $malformed), [
            'notes' => 'Forbidden cross-domain mutation.',
        ])->assertNotFound();
        $this->assertSame('Consultation notes.', $malformed->fresh()->notes);

        $this->removeRolePermissions(AuthorizationProfile::ROLE_STAFF, [
            'pet-care-clinic.consultations.view-own',
            'pet-care-clinic.consultations.view-all',
        ]);
        $staff = $staff->fresh();
        $this->assertTrue($staff->can(AuthorizationProfile::PERMISSION_STAFF_ACCESS));
        $this->assertSame([], PetCareConsultation::query()
            ->forPetCareDomain()
            ->visibleTo($staff)
            ->pluck('id')
            ->all());
        $this->actingAs($staff);
        foreach (['view', 'update', 'assign', 'close'] as $ability) {
            $this->assertFalse(Gate::allows($ability, $other));
        }
        $this->actingAs($staff)
            ->get(route('petcare.consultations.index'))
            ->assertForbidden();
        $this->put(route('petcare.consultations.update', $other), [
            'status' => $other->fresh()->status,
        ])->assertForbidden();
        $this->get(route('petcare.consultations.show', $other))->assertForbidden();
    }

    public function test_ordinary_update_permission_controls_only_actual_notes_schedule_and_nonterminal_changes(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $pet = $this->petFor($owner, PetProfile::DOMAIN_PETCARE, 'Ordinary update pet');
        $consultation = $this->consultationFor($owner, $pet, 'Ordinary update consultation', [
            'scheduled_for' => '2026-08-20 12:00:00',
        ]);

        $this->actingAs($owner)
            ->put(route('petcare.consultations.update', $consultation), [
                'notes' => 'Owner changed notes.',
                'scheduled_for' => null,
                'status' => 'in_progress',
                'subject' => 'Protected subject mutation',
                'pet_profile_id' => 999999,
                'user_id' => 999999,
            ])
            ->assertRedirect(route('petcare.consultations.show', $consultation));
        $consultation->refresh();
        $this->assertSame('Owner changed notes.', $consultation->notes);
        $this->assertNull($consultation->scheduled_for);
        $this->assertSame('in_progress', $consultation->status);
        $this->assertSame('Ordinary update consultation', $consultation->subject);
        $this->assertSame($pet->id, $consultation->pet_profile_id);
        $this->assertSame($owner->id, $consultation->user_id);

        $this->put(route('petcare.consultations.update', $consultation), [
            'notes' => null,
        ])->assertRedirect(route('petcare.consultations.show', $consultation));
        $this->assertNull($consultation->fresh()->notes);

        Notification::fake();
        $auditCount = AuditLog::query()
            ->where('event', 'petcare.consultation.updated')
            ->count();
        $this->put(route('petcare.consultations.update', $consultation), [
            'status' => 'in_progress',
            'notes' => null,
            'subject' => 'Unknown-only mutation',
        ])->assertSessionHasErrors('consultation');
        $this->assertSame($auditCount, AuditLog::query()
            ->where('event', 'petcare.consultation.updated')
            ->count());
        Notification::assertNothingSent();

        $this->removeRolePermissions(
            AuthorizationProfile::ROLE_SERVICE_USER,
            ['pet-care-clinic.consultations.update-own'],
        );
        $owner = $owner->fresh();
        $this->actingAs($owner)
            ->get(route('petcare.consultations.show', $consultation))
            ->assertOk()
            ->assertSee('In Progress')
            ->assertSee('No notes recorded.')
            ->assertDontSee('name="notes"', false)
            ->assertDontSee('name="scheduled_for"', false);
        $this->put(route('petcare.consultations.update', $consultation), [
            'notes' => 'Legacy broad owner mutation.',
        ])->assertForbidden();
        $this->assertNull($consultation->fresh()->notes);
    }

    public function test_form_shaped_ordinary_update_preserves_schedule_second_precision(): void
    {
        $owner = User::factory()->create();
        $pet = $this->petFor($owner, PetProfile::DOMAIN_PETCARE, 'Schedule precision pet');
        $consultation = $this->consultationFor($owner, $pet, 'Schedule precision consultation', [
            'scheduled_for' => '2026-08-20 12:34:56',
        ]);

        $response = $this->actingAs($owner)
            ->get(route('petcare.consultations.show', $consultation));
        $response->assertOk();
        $updateForm = $this->renderedForm($response->getContent(), 'consultation-update-form');
        $this->assertStringContainsString('value="20/08/2026 13:34:56"', $updateForm);
        $this->assertStringContainsString('placeholder="dd/mm/yyyy HH:mm:ss"', $updateForm);
        $this->assertStringNotContainsString('datetime-local', $updateForm);

        $this->put(route('petcare.consultations.update', $consultation), [
            'status' => 'open',
            'scheduled_for' => '20/08/2026 13:34:56',
            'notes' => 'An unrelated form-shaped notes edit.',
        ])->assertRedirect(route('petcare.consultations.show', $consultation));

        $consultation->refresh();
        $this->assertSame('2026-08-20 12:34:56', $consultation->scheduled_for?->format('Y-m-d H:i:s'));
        $this->assertSame('An unrelated form-shaped notes edit.', $consultation->notes);
    }

    public function test_scheduled_for_shows_and_stores_uk_day_month_order(): void
    {
        $owner = User::factory()->create();
        $pet = $this->petFor($owner, PetProfile::DOMAIN_PETCARE, 'UK schedule pet');

        $this->actingAs($owner)->post(route('petcare.consultations.store'), [
            'pet_profile_id' => $pet->id,
            'subject' => 'UK scheduled date',
            'scheduled_for' => '05/03/2026 14:30:00',
        ])->assertRedirect();

        $consultation = PetCareConsultation::query()
            ->where('subject', 'UK scheduled date')
            ->firstOrFail();
        $this->assertSame(
            '2026-03-05 14:30:00',
            $consultation->scheduled_for?->timezone('UTC')->format('Y-m-d H:i:s'),
        );

        $this->get(route('petcare.consultations.show', $consultation))
            ->assertOk()
            ->assertSee('05/03/2026 14:30:00')
            ->assertSee('dd/mm/yyyy')
            ->assertDontSee('03/05/2026')
            ->assertDontSee('2026-05-03');
        $this->get(route('petcare.consultations.index'))
            ->assertOk()
            ->assertSee('05/03/2026 14:30:00');

        $this->post(route('petcare.consultations.store'), [
            'pet_profile_id' => $pet->id,
            'subject' => 'Invalid UK scheduled date',
            'scheduled_for' => '32/01/2026 10:00:00',
        ])->assertSessionHasErrors([
            'scheduled_for' => 'Enter a UK date as dd/mm/yyyy, optionally with HH:mm or HH:mm:ss.',
        ]);
        $this->assertDatabaseMissing('pet_care_consultations', [
            'subject' => 'Invalid UK scheduled date',
        ]);
    }

    public function test_mutation_permissions_and_visibility_authorize_before_validation(): void
    {
        $owner = User::factory()->create();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $candidate = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $pet = $this->petFor($owner, PetProfile::DOMAIN_PETCARE, 'Authorization order pet');
        $consultation = $this->consultationFor($owner, $pet, 'Authorization order consultation');

        $this->removeRolePermissions(AuthorizationProfile::ROLE_ADMINISTRATOR, [
            'pet-care-clinic.consultations.update-all',
        ]);
        foreach ([
            ['invalid ordinary', ['notes' => ['invalid']]],
            ['valid ordinary', ['notes' => 'Forbidden ordinary change.']],
        ] as [$scenario, $payload]) {
            $response = $this->actingAs($actor->fresh())
                ->put(route('petcare.consultations.update', $consultation), $payload);
            $this->assertSame(
                403,
                $response->getStatusCode(),
                "The {$scenario} request did not authorize before validation.",
            );
        }
        $this->restoreRolePermissions(AuthorizationProfile::ROLE_ADMINISTRATOR, [
            'pet-care-clinic.consultations.update-all',
        ]);
        $this->removeRolePermissions(AuthorizationProfile::ROLE_ADMINISTRATOR, [
            'pet-care-clinic.consultations.assign',
        ]);
        foreach ([
            ['invalid assignment', ['assigned_to' => 'not-an-id']],
            ['valid assignment', ['assigned_to' => $candidate->id]],
        ] as [$scenario, $payload]) {
            $response = $this->actingAs($actor->fresh())
                ->put(route('petcare.consultations.update', $consultation), $payload);
            $this->assertSame(
                403,
                $response->getStatusCode(),
                "The {$scenario} request did not authorize before validation.",
            );
        }
        $this->restoreRolePermissions(AuthorizationProfile::ROLE_ADMINISTRATOR, [
            'pet-care-clinic.consultations.assign',
        ]);
        $this->removeRolePermissions(AuthorizationProfile::ROLE_ADMINISTRATOR, [
            'pet-care-clinic.consultations.close',
        ]);
        foreach ([
            ['invalid close', ['status' => 'closed', 'scheduled_for' => ['invalid']]],
            ['valid close', ['status' => 'closed']],
        ] as [$scenario, $payload]) {
            $response = $this->actingAs($actor->fresh())
                ->put(route('petcare.consultations.update', $consultation), $payload);
            $this->assertSame(
                403,
                $response->getStatusCode(),
                "The {$scenario} request did not authorize before validation.",
            );
        }
        $this->restoreRolePermissions(AuthorizationProfile::ROLE_ADMINISTRATOR, [
            'pet-care-clinic.consultations.close',
        ]);
        $this->assertConsultationUnchanged($consultation);

        $this->removeRolePermissions(AuthorizationProfile::ROLE_ADMINISTRATOR, [
            'pet-care-clinic.consultations.view-all',
        ]);

        foreach ([
            ['notes' => 'Hidden ordinary change.'],
            ['assigned_to' => $candidate->id],
            ['status' => 'closed'],
        ] as $payload) {
            $this->actingAs($actor->fresh())
                ->put(route('petcare.consultations.update', $consultation), $payload)
                ->assertForbidden();
        }
        $this->assertConsultationUnchanged($consultation);
    }

    public function test_unauthorized_assignment_payload_cannot_reveal_the_current_assignee(): void
    {
        $owner = User::factory()->create();
        $currentAssignee = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create(['name' => 'Hidden current assignee']);
        $otherAssignee = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create(['name' => 'Other hidden assignee']);
        $pet = $this->petFor(
            $owner,
            PetProfile::DOMAIN_PETCARE,
            'Private assignment pet',
        );
        $consultation = $this->consultationFor(
            $owner,
            $pet,
            'Private assignment consultation',
            ['assigned_to' => $currentAssignee->id],
        );

        $this->actingAs($owner);
        $this->assertFalse($owner->can(
            'pet-care-clinic.consultations.assign',
        ));
        $this->get(route('petcare.consultations.show', $consultation))
            ->assertOk()
            ->assertDontSee($currentAssignee->name)
            ->assertDontSee($otherAssignee->name);

        foreach ([
            'current assignee' => ['assigned_to' => $currentAssignee->id],
            'different assignee' => ['assigned_to' => $otherAssignee->id],
            'unassignment' => ['assigned_to' => null],
            'invalid assignee' => ['assigned_to' => 'not-an-id'],
        ] as $scenario => $payload) {
            $response = $this->put(
                route('petcare.consultations.update', $consultation),
                $payload,
            );
            $this->assertSame(
                403,
                $response->getStatusCode(),
                "The {$scenario} guess exposed assignment state.",
            );
        }

        $this->assertSame(
            $currentAssignee->id,
            $consultation->fresh()->assigned_to,
        );
        $audits = AuditLog::query()
            ->where('event', 'authorization.assignment_denied')
            ->where('user_id', $owner->id)
            ->get();
        $this->assertCount(4, $audits);
        foreach ($audits as $audit) {
            $this->assertArrayNotHasKey('assigned_to', $audit->context);
        }
    }

    public function test_assignment_is_independent_atomic_and_candidates_require_exact_view_all(): void
    {
        $owner = User::factory()->create();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $eligibleCandidate = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create(['name' => 'Exact eligible candidate']);
        $wrongInstanceCandidate = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create(['name' => 'Broad staff candidate']);
        $suspendedCandidate = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create([
                'name' => 'Suspended candidate',
                'suspended_at' => now(),
                'suspension_reason' => 'test',
            ]);
        $permissionOnly = $this->permissionOnlyUser(
            $actor,
            'pet-care-clinic.consultations.view-all',
            'Permission-only candidate',
        );
        $pet = $this->petFor($owner, PetProfile::DOMAIN_PETCARE, 'Assignment pet');
        $consultation = $this->consultationFor($owner, $pet, 'Assignment consultation');
        $this->removeRolePermissions(AuthorizationProfile::ROLE_STAFF, [
            'pet-care-clinic.consultations.view-all',
        ]);
        $this->removeRolePermissions(AuthorizationProfile::ROLE_ADMINISTRATOR, [
            'pet-care-clinic.consultations.update-all',
            'pet-care-clinic.consultations.close',
        ]);

        $response = $this->actingAs($actor->fresh())
            ->get(route('petcare.consultations.show', $consultation));
        $response->assertOk()
            ->assertSee('name="assigned_to"', false)
            ->assertSee($eligibleCandidate->name)
            ->assertDontSee($wrongInstanceCandidate->name)
            ->assertDontSee($suspendedCandidate->name)
            ->assertDontSee($permissionOnly->name)
            ->assertDontSee('name="notes"', false);

        $this->put(route('petcare.consultations.update', $consultation), [
            'assigned_to' => $eligibleCandidate->id,
        ])->assertRedirect(route('petcare.consultations.show', $consultation));
        $this->assertSame($eligibleCandidate->id, $consultation->fresh()->assigned_to);

        $this->put(route('petcare.consultations.update', $consultation), [
            'assigned_to' => null,
        ])->assertRedirect(route('petcare.consultations.show', $consultation));
        $this->assertNull($consultation->fresh()->assigned_to);
        $this->put(route('petcare.consultations.update', $consultation), [
            'assigned_to' => $eligibleCandidate->id,
        ])->assertRedirect(route('petcare.consultations.show', $consultation));

        $this->put(route('petcare.consultations.update', $consultation), [
            'assigned_to' => null,
            'notes' => 'Mixed unauthorized notes.',
        ])->assertForbidden();
        $consultation->refresh();
        $this->assertSame($eligibleCandidate->id, $consultation->assigned_to);
        $this->assertSame('Consultation notes.', $consultation->notes);

        $this->put(route('petcare.consultations.update', $consultation), [
            'assigned_to' => $wrongInstanceCandidate->id,
        ])->assertSessionHasErrors('assigned_to');
        $this->assertSame($eligibleCandidate->id, $consultation->fresh()->assigned_to);

        $ownerProbe = User::factory()->create();
        $ownerProbePet = $this->petFor(
            $ownerProbe,
            PetProfile::DOMAIN_PETCARE,
            'Assignment denial audit pet',
        );
        $ownerProbeConsultation = $this->consultationFor(
            $ownerProbe,
            $ownerProbePet,
            'Assignment denial audit consultation',
        );
        $this->actingAs($ownerProbe)
            ->put(route('petcare.consultations.update', $ownerProbeConsultation), [
                'assigned_to' => $eligibleCandidate->id,
            ])
            ->assertForbidden();
        $denial = AuditLog::query()
            ->where('event', 'authorization.assignment_denied')
            ->where('auditable_type', 'consultation')
            ->where('auditable_id', $ownerProbeConsultation->id)
            ->firstOrFail();
        $denialKeys = array_keys($denial->context);
        sort($denialKeys);
        $this->assertSame(
            ['actor_id', 'method', 'reason_code', 'route_name'],
            $denialKeys,
        );

        $this->removeRolePermissions(
            AuthorizationProfile::ROLE_ADMINISTRATOR,
            ['pet-care-clinic.consultations.assign'],
        );
        $this->actingAs($actor->fresh())
            ->put(route('petcare.consultations.update', $consultation), [
                'assigned_to' => null,
            ])
            ->assertForbidden();
        $this->assertSame($eligibleCandidate->id, $consultation->fresh()->assigned_to);
    }

    public function test_unavailable_existing_assignees_are_preserved_but_not_offered_by_the_assignment_ui(): void
    {
        $owner = User::factory()->create();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)
            ->create();
        $suspended = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create([
                'name' => 'Suspended former assignee',
                'suspended_at' => now(),
                'suspension_reason' => 'test',
            ]);
        $revoked = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create(['name' => 'Revoked former assignee']);
        $wrongInstance = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create(['name' => 'Other instance former assignee']);
        $pet = $this->petFor($owner, PetProfile::DOMAIN_PETCARE, 'Preserved assignment pet');
        $consultations = collect([
            [$suspended, 'Suspended preserved assignment'],
            [$revoked, 'Revoked preserved assignment'],
            [$wrongInstance, 'Wrong-instance preserved assignment'],
        ])->mapWithKeys(fn (array $scenario): array => [
            $scenario[0]->id => $this->consultationFor($owner, $pet, $scenario[1], [
                'assigned_to' => $scenario[0]->id,
            ]),
        ]);

        $this->removeRolePermissions(AuthorizationProfile::ROLE_ADMINISTRATOR, [
            'pet-care-clinic.consultations.view-all',
        ]);
        $this->removeRolePermissions(AuthorizationProfile::ROLE_STAFF, [
            'pet-care-clinic.consultations.view-all',
        ]);
        $wrongInstance = $wrongInstance->fresh();
        $this->actingAs($wrongInstance);
        $this->assertTrue($wrongInstance->can('apes-cic.tickets.view-all'));

        foreach ([$suspended, $revoked, $wrongInstance] as $unavailableAssignee) {
            $consultation = $consultations->get($unavailableAssignee->id);
            $response = $this->actingAs($actor)
                ->get(route('petcare.consultations.show', $consultation));

            $response->assertOk()
                ->assertSee($unavailableAssignee->name)
                ->assertSee('Current assignment is preserved but is no longer eligible.', false);
            $updateForm = $this->renderedForm($response->getContent(), 'consultation-update-form');
            $assignmentForm = $this->renderedForm(
                $response->getContent(),
                'consultation-assignment-form',
            );
            $this->assertStringNotContainsString('name="assigned_to"', $updateForm);
            $this->assertStringContainsString('name="assigned_to"', $assignmentForm);
            $this->assertStringNotContainsString(
                'value="'.$unavailableAssignee->id.'"',
                $assignmentForm,
            );

            $this->put(route('petcare.consultations.update', $consultation), [
                'notes' => 'Ordinary update preserves assignment '.$unavailableAssignee->id,
            ])->assertRedirect(route('petcare.consultations.show', $consultation));
            $this->assertSame(
                $unavailableAssignee->id,
                $consultation->fresh()->assigned_to,
            );
        }
    }

    public function test_close_permission_is_independent_and_timestamps_only_true_terminal_transitions(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-14 10:00:00');
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $pet = $this->petFor($owner, PetProfile::DOMAIN_PETCARE, 'Terminal pet');
        $consultation = $this->consultationFor($owner, $pet, 'Terminal consultation');
        $this->removeRolePermissions(AuthorizationProfile::ROLE_STAFF, [
            'pet-care-clinic.consultations.update-all',
            'pet-care-clinic.consultations.assign',
        ]);

        $this->actingAs($staff->fresh())
            ->get(route('petcare.consultations.show', $consultation))
            ->assertOk()
            ->assertSee('name="status"', false)
            ->assertSee('value="closed"', false)
            ->assertDontSee('name="notes"', false)
            ->assertDontSee('name="assigned_to"', false);
        $this->put(route('petcare.consultations.update', $consultation), [
            'status' => 'closed',
        ])->assertRedirect(route('petcare.consultations.show', $consultation));
        $closedAt = $consultation->fresh()->closed_at;
        $this->assertNotNull($closedAt);

        $this->travel(5)->minutes();
        $this->put(route('petcare.consultations.update', $consultation), [
            'status' => 'closed',
        ])->assertSessionHasErrors('consultation');
        $this->assertTrue($consultation->fresh()->closed_at->equalTo($closedAt));

        $this->put(route('petcare.consultations.update', $consultation), [
            'status' => 'open',
            'notes' => 'Mixed unauthorized reopen notes.',
        ])->assertForbidden();
        $this->assertSame('closed', $consultation->fresh()->status);
        $this->assertTrue($consultation->fresh()->closed_at->equalTo($closedAt));

        $this->put(route('petcare.consultations.update', $consultation), [
            'status' => 'open',
        ])->assertRedirect(route('petcare.consultations.show', $consultation));
        $this->assertSame('open', $consultation->fresh()->status);
        $this->assertNull($consultation->fresh()->closed_at);

        $this->travel(5)->minutes();
        $this->put(route('petcare.consultations.update', $consultation), [
            'status' => 'closed',
        ])->assertRedirect(route('petcare.consultations.show', $consultation));
        $reclosedAt = $consultation->fresh()->closed_at;
        $this->assertNotNull($reclosedAt);
        $this->assertFalse($reclosedAt->equalTo($closedAt));
        $this->put(route('petcare.consultations.update', $consultation), [
            'status' => 'open',
        ])->assertRedirect(route('petcare.consultations.show', $consultation));

        $this->removeRolePermissions(
            AuthorizationProfile::ROLE_STAFF,
            ['pet-care-clinic.consultations.close'],
        );
        $this->actingAs($staff->fresh())
            ->put(route('petcare.consultations.update', $consultation), [
                'status' => 'closed',
            ])
            ->assertForbidden();
        $this->assertSame('open', $consultation->fresh()->status);
        $this->assertNull($consultation->fresh()->closed_at);
    }

    public function test_notifications_are_exact_eligible_private_and_audited_only_after_save(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $recipient = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $wrongInstance = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $suspended = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create([
                'suspended_at' => now(),
                'suspension_reason' => 'test',
            ]);
        $permissionOnly = $this->permissionOnlyUser(
            $actor,
            'pet-care-clinic.consultations.view-all',
            'Permission-only recipient',
        );
        $pet = $this->petFor($owner, PetProfile::DOMAIN_PETCARE, 'Notification pet');
        $consultation = $this->consultationFor($owner, $pet, 'Notification consultation');
        $this->removeRolePermissions(AuthorizationProfile::ROLE_STAFF, [
            'pet-care-clinic.consultations.view-all',
        ]);
        $privateNotes = 'Private clinical note that must not leave the record.';

        $response = $this->actingAs($actor)
            ->put(route('petcare.consultations.update', $consultation), [
                'notes' => $privateNotes,
            ]);
        $this->assertSame(
            route('petcare.consultations.show', $consultation),
            $response->headers->get('Location'),
        );

        foreach ([$owner, $recipient] as $expectedRecipient) {
            Notification::assertSentTo(
                $expectedRecipient,
                ConsultationUpdatedNotification::class,
                function (ConsultationUpdatedNotification $notification) use ($expectedRecipient, $privateNotes): bool {
                    $metadata = $notification->toArray($expectedRecipient);
                    $rendered = $notification->toMail($expectedRecipient)->render();
                    $mail = is_object($rendered) && method_exists($rendered, 'toHtml')
                        ? $rendered->toHtml()
                        : (string) $rendered;

                    return ! str_contains(
                        json_encode($metadata, JSON_THROW_ON_ERROR),
                        $privateNotes,
                    ) && ! str_contains($mail, $privateNotes);
                },
            );
        }
        Notification::assertNotSentTo($actor, ConsultationUpdatedNotification::class);
        Notification::assertNotSentTo($wrongInstance, ConsultationUpdatedNotification::class);
        Notification::assertNotSentTo($suspended, ConsultationUpdatedNotification::class);
        Notification::assertNotSentTo($permissionOnly, ConsultationUpdatedNotification::class);

        $audit = AuditLog::query()
            ->where('event', 'petcare.consultation.updated')
            ->where('auditable_type', 'consultation')
            ->where('auditable_id', $consultation->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('pet-care-clinic', $audit->context['sub_core_key']);
        $this->assertSame('consultations', $audit->context['module_key']);
        $this->assertStringNotContainsString(
            $privateNotes,
            json_encode($audit->context, JSON_THROW_ON_ERROR),
        );

        Notification::fake();
        $this->removeRolePermissions(
            AuthorizationProfile::ROLE_SERVICE_USER,
            ['pet-care-clinic.consultations.view-own'],
        );
        $this->actingAs($actor)
            ->put(route('petcare.consultations.update', $consultation), [
                'notes' => 'Owner can no longer view this update.',
            ])
            ->assertRedirect(route('petcare.consultations.show', $consultation));
        Notification::assertNotSentTo($owner, ConsultationUpdatedNotification::class);
        Notification::assertSentTo($recipient, ConsultationUpdatedNotification::class);
    }

    public function test_owner_who_is_also_exact_eligible_staff_receives_one_notification(): void
    {
        Notification::fake();
        $ownerAndStaff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)
            ->create();
        $pet = $this->petFor(
            $ownerAndStaff,
            PetProfile::DOMAIN_PETCARE,
            'Overlapping recipient pet',
        );
        $consultation = $this->consultationFor(
            $ownerAndStaff,
            $pet,
            'Overlapping recipient consultation',
        );

        $this->actingAs($actor)
            ->put(route('petcare.consultations.update', $consultation), [
                'notes' => 'One deduplicated notification.',
            ])
            ->assertRedirect(route('petcare.consultations.show', $consultation));

        Notification::assertSentToTimes(
            $ownerAndStaff,
            ConsultationUpdatedNotification::class,
            1,
        );
    }

    public function test_owner_summary_is_private_and_stops_contributing_after_visibility_revocation(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $ownerPet = $this->petFor($owner, PetProfile::DOMAIN_PETCARE, 'Private summary owner pet');
        $otherPet = $this->petFor($otherOwner, PetProfile::DOMAIN_PETCARE, 'Private summary other pet');
        $this->consultationFor($owner, $ownerPet, 'Private summary owned consultation');
        $this->consultationFor($otherOwner, $otherPet, 'Private summary other consultation');
        $instance = app(ModuleRegistry::class)
            ->instance('pet-care-clinic', 'consultations');
        $summaryProvider = app($instance->summaryProviderClass());

        $this->actingAs($owner);
        $summary = $summaryProvider->summarize($instance, $owner);
        $this->assertSame(1, $summary->total);
        $this->assertSame(1, $summary->active);

        $this->removeRolePermissions(AuthorizationProfile::ROLE_SERVICE_USER, [
            'pet-care-clinic.consultations.view-own',
        ]);
        $owner = $owner->fresh();
        $this->actingAs($owner);
        $summary = $summaryProvider->summarize($instance, $owner);
        $this->assertSame(0, $summary->total);
        $this->assertSame(0, $summary->active);
    }

    public function test_analytics_includes_exact_lower_boundaries_and_is_empty_when_disabled(): void
    {
        $owner = User::factory()->create();
        $superAdmin = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)
            ->create();
        $pet = $this->petFor($owner, PetProfile::DOMAIN_PETCARE, 'Analytics state pet');
        $this->consultationFor($owner, $pet, 'Analytics exact lower boundary', [
            'status' => 'closed',
            'created_at' => '2026-08-01 00:00:00',
            'updated_at' => '2026-08-01 00:00:00',
            'closed_at' => '2026-08-01 00:00:00',
        ]);
        $instance = app(ModuleRegistry::class)
            ->instance('pet-care-clinic', 'consultations');
        /** @var ModuleAnalyticsProvider $analytics */
        $analytics = app($instance->analyticsProviderClass());
        $from = Carbon::parse('2026-08-01 00:00:00', 'UTC');
        $to = Carbon::parse('2026-08-02 00:00:00', 'UTC');

        $snapshot = $analytics->snapshot($instance, $from, $to, 'UTC');
        $this->assertSame(1, $snapshot->total);
        $this->assertSame(['2026-08-01' => 1], $snapshot->createdPerDay);
        $this->assertSame(['2026-08-01' => 1], $snapshot->closedPerDay);
        $this->assertSame(1, $snapshot->closureSampleSize);

        $this->actingAs($superAdmin);
        app(ModuleLifecycleManager::class)->disable(
            $superAdmin,
            'pet-care-clinic',
            'consultations',
            (int) $this->installation()->lock_version,
        );
        $snapshot = $analytics->snapshot($instance, $from, $to, 'UTC');
        $this->assertSame(0, $snapshot->total);
        $this->assertSame(0, $snapshot->open);
        $this->assertSame(0, $snapshot->highOrUrgent);
        $this->assertSame(0, $snapshot->unassigned);
        $this->assertSame([], $snapshot->createdPerDay);
        $this->assertSame([], $snapshot->closedPerDay);
        $this->assertNull($snapshot->medianClosureMinutes);
        $this->assertSame(0, $snapshot->closureSampleSize);
    }

    public function test_summary_activity_attention_analytics_and_detector_share_domain_and_open_contracts(): void
    {
        Carbon::setTestNow('2026-08-14 12:00:00');
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $ownerPet = $this->petFor($owner, PetProfile::DOMAIN_PETCARE, 'Provider owner pet');
        $otherPet = $this->petFor($otherOwner, PetProfile::DOMAIN_PETCARE, 'Provider other pet');
        $shelterPet = $this->petFor($owner, PetProfile::DOMAIN_SHELTER, 'Provider shelter pet');
        $open = $this->consultationFor($owner, $ownerPet, 'Provider strict open', [
            'assigned_to' => null,
            'created_at' => '2026-08-01 23:30:00',
            'updated_at' => '2026-08-02 08:00:00',
        ]);
        $closed = $this->consultationFor($otherOwner, $otherPet, 'Provider closed', [
            'status' => 'closed',
            'created_at' => '2026-08-01 23:30:00',
            'updated_at' => '2026-08-02 09:00:00',
            'closed_at' => '2026-08-02 00:30:00',
        ]);
        $inconsistentClosed = $this->consultationFor($owner, $ownerPet, 'Provider timestamp closed', [
            'status' => 'open',
            'created_at' => '2026-08-02 00:00:00',
            'updated_at' => '2026-08-02 10:00:00',
            'closed_at' => '2026-08-02 01:30:00',
        ]);
        $inconsistentStatus = $this->consultationFor($owner, $ownerPet, 'Provider status closed', [
            'status' => 'closed',
            'created_at' => '2026-08-02 01:00:00',
            'updated_at' => '2026-08-02 11:00:00',
            'closed_at' => null,
        ]);
        $foreign = $this->consultationFor($owner, $shelterPet, 'Provider foreign domain', [
            'created_at' => '2026-08-02 00:15:00',
            'updated_at' => '2026-08-02 11:30:00',
        ]);
        $this->consultationFor($owner, $ownerPet, 'Provider upper boundary', [
            'created_at' => '2026-08-03 00:00:00',
            'updated_at' => '2026-08-03 00:00:00',
            'closed_at' => '2026-08-03 00:00:00',
            'status' => 'closed',
        ]);

        $instance = app(ModuleRegistry::class)
            ->instance('pet-care-clinic', 'consultations');
        $this->assertNotNull($instance->recentActivityProviderClass());
        $this->assertNotNull($instance->analyticsProviderClass());
        $this->assertTrue(is_a(
            $instance->recentActivityProviderClass(),
            ModuleRecentActivityProvider::class,
            true,
        ));
        $this->assertTrue(is_a(
            $instance->analyticsProviderClass(),
            ModuleAnalyticsProvider::class,
            true,
        ));

        /** @var ModuleRecentActivityProvider $recent */
        $recent = app($instance->recentActivityProviderClass());
        $this->actingAs($owner);
        $ownerActivity = $recent->recent($instance, $owner, 5);
        $this->assertSame(
            ['Provider upper boundary', 'Provider status closed', 'Provider timestamp closed', 'Provider strict open'],
            collect($ownerActivity)->pluck('title')->all(),
        );
        $this->assertSame(
            ['petcare.consultations.show'],
            collect($ownerActivity)->pluck('routeName')->unique()->values()->all(),
        );
        $this->actingAs($staff);
        $this->assertSame(
            [
                PetCareConsultation::query()->where('subject', 'Provider upper boundary')->value('id'),
                $inconsistentStatus->id,
                $inconsistentClosed->id,
                $closed->id,
                $open->id,
            ],
            collect($recent->recent($instance, $staff, 5))->pluck('recordId')->all(),
        );

        /** @var ModuleAttentionProvider $attention */
        $attention = app($instance->attentionProviderClass());
        $attentionItems = $attention->attention($instance, $staff, 10);
        $this->assertSame(['Provider strict open'], collect($attentionItems)->pluck('title')->all());
        $this->assertSame('APES Pet Care Clinic', $attentionItems[0]->service);

        /** @var ModuleAnalyticsProvider $analytics */
        $analytics = app($instance->analyticsProviderClass());
        $snapshot = $analytics->snapshot(
            $instance,
            Carbon::parse('2026-08-01 00:00:00', 'UTC'),
            Carbon::parse('2026-08-03 00:00:00', 'UTC'),
            'Europe/London',
        );
        $this->assertSame('pet-care-clinic:consultations', $snapshot->instanceKey);
        $this->assertSame(4, $snapshot->total);
        $this->assertSame(1, $snapshot->open);
        $this->assertSame(0, $snapshot->highOrUrgent);
        $this->assertSame(1, $snapshot->unassigned);
        $this->assertSame(['2026-08-02' => 4], $snapshot->createdPerDay);
        $this->assertSame(['2026-08-02' => 2], $snapshot->closedPerDay);
        $this->assertSame(75.0, $snapshot->medianClosureMinutes);
        $this->assertSame(2, $snapshot->closureSampleSize);

        /** @var ModuleActiveRecordDetector $detector */
        $detector = app($instance->module->activeRecordDetector);
        $this->assertSame(1, $detector->count($instance));
        $summaryProvider = app($instance->summaryProviderClass());
        $summary = $summaryProvider->summarize($instance, $staff);
        $this->assertSame(5, $summary->total);
        $this->assertSame(1, $summary->active);
        $this->assertSame('1 open', $summary->detail);
        $this->assertNotContains($foreign->id, collect($ownerActivity)->pluck('recordId')->all());

        $this->actingAs($staff);
        foreach (['view', 'update', 'assign', 'close'] as $ability) {
            $this->assertFalse(Gate::allows($ability, $foreign));
        }
        $orphan = new PetCareConsultation([
            'pet_profile_id' => 999999,
            'user_id' => $owner->id,
            'subject' => 'Missing linked pet',
            'status' => 'open',
        ]);
        foreach (['view', 'update', 'assign', 'close'] as $ability) {
            $this->assertFalse(Gate::allows($ability, $orphan));
        }

        PetCareConsultation::query()->delete();
        $empty = $analytics->snapshot(
            $instance,
            Carbon::parse('2026-08-01 00:00:00', 'UTC'),
            Carbon::parse('2026-08-03 00:00:00', 'UTC'),
            'Europe/London',
        );
        $this->assertSame(0, $empty->total);
        $this->assertSame(0, $empty->open);
        $this->assertSame(0, $empty->highOrUrgent);
        $this->assertSame(0, $empty->unassigned);
        $this->assertSame([], $empty->createdPerDay);
        $this->assertSame([], $empty->closedPerDay);
        $this->assertNull($empty->medianClosureMinutes);
        $this->assertSame(0, $empty->closureSampleSize);
    }

    public function test_disablement_ignores_foreign_rows_blocks_strict_open_rows_and_retains_data(): void
    {
        $superAdmin = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)
            ->create();
        $owner = User::factory()->create();
        $shelterPet = $this->petFor($owner, PetProfile::DOMAIN_SHELTER, 'Lifecycle Shelter pet');
        $foreign = $this->consultationFor($owner, $shelterPet, 'Lifecycle foreign consultation');
        $lifecycle = app(ModuleLifecycleManager::class);
        $installation = $this->installation();
        $this->actingAs($superAdmin);
        $instance = app(ModuleRegistry::class)
            ->instance('pet-care-clinic', 'consultations');
        /** @var ModuleActiveRecordDetector $detector */
        $detector = app($instance->module->activeRecordDetector);
        $this->assertSame(0, $detector->count($instance));

        $disabled = $lifecycle->disable(
            $superAdmin,
            'pet-care-clinic',
            'consultations',
            (int) $installation->lock_version,
        );
        $this->assertFalse($disabled->enabled);
        $this->assertDatabaseHas('pet_care_consultations', ['id' => $foreign->id]);
        $this->actingAs($owner)
            ->get(route('petcare.consultations.index'))
            ->assertNotFound();
        $this->get(route('petcare.index'))
            ->assertOk()
            ->assertDontSee('data-module-instance="pet-care-clinic:consultations"', false)
            ->assertDontSee($foreign->subject);

        $this->actingAs($superAdmin);
        $enabled = $lifecycle->enable(
            $superAdmin,
            'pet-care-clinic',
            'consultations',
            (int) $disabled->lock_version,
        );
        $pet = $this->petFor($owner, PetProfile::DOMAIN_PETCARE, 'Lifecycle Clinic pet');
        $open = $this->consultationFor($owner, $pet, 'Lifecycle open consultation');

        try {
            $lifecycle->disable(
                $superAdmin,
                'pet-care-clinic',
                'consultations',
                (int) $enabled->lock_version,
            );
            $this->fail('An open Pet Care consultation did not block disablement.');
        } catch (ModuleLifecycleException $exception) {
            $this->assertSame('active_records', $exception->reason);
        }
        $this->assertTrue($this->installation()->enabled);
        $this->assertDatabaseHas('pet_care_consultations', ['id' => $open->id]);
    }

    private function petFor(User $owner, string $domain, string $name): PetProfile
    {
        return PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => $domain,
            'name' => $name,
            'species' => 'cat',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);
    }

    private function consultationFor(
        User $owner,
        PetProfile $pet,
        string $subject,
        array $attributes = [],
    ): PetCareConsultation {
        $timestamps = array_intersect_key(
            $attributes,
            array_flip(['created_at', 'updated_at']),
        );
        $consultation = PetCareConsultation::query()->create(array_merge([
            'pet_profile_id' => $pet->id,
            'user_id' => $owner->id,
            'subject' => $subject,
            'status' => 'open',
            'notes' => 'Consultation notes.',
        ], $attributes));

        if ($timestamps !== []) {
            $consultation->timestamps = false;
            $consultation->forceFill($timestamps)->saveQuietly();
            $consultation->timestamps = true;
        }

        return $consultation->fresh();
    }

    private function assertConsultationUnchanged(PetCareConsultation $consultation): void
    {
        $consultation->refresh();
        $this->assertSame('Consultation notes.', $consultation->notes);
        $this->assertSame('open', $consultation->status);
        $this->assertNull($consultation->assigned_to);
        $this->assertNull($consultation->closed_at);
    }

    private function permissionOnlyUser(
        User $actor,
        string $permissionName,
        string $name,
    ): User {
        $user = User::factory()->create(['name' => $name]);
        $role = Role::query()->create([
            'name' => 'consultation-helper-'.str()->random(8),
            'guard_name' => 'web',
            'is_protected' => false,
        ]);
        $role->permissions()->attach(Permission::query()
            ->where('guard_name', 'web')
            ->where('name', $permissionName)
            ->value('id'));
        app(AuthorizationRoleMaterializer::class)->grant(
            $user,
            $role,
            RoleSource::SOURCE_LOCAL,
            actor: $actor,
        );

        return $user->fresh();
    }

    /** @param array<int, string> $permissionNames */
    private function removeRolePermissions(
        string $roleName,
        array $permissionNames,
    ): void {
        $role = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $roleName)
            ->firstOrFail();
        $permissionIds = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $permissionNames)
            ->pluck('id');
        $this->assertCount(count($permissionNames), $permissionIds);
        $role->permissions()->detach($permissionIds);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param array<int, string> $permissionNames */
    private function restoreRolePermissions(
        string $roleName,
        array $permissionNames,
    ): void {
        $role = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $roleName)
            ->firstOrFail();
        $permissionIds = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $permissionNames)
            ->pluck('id');
        $this->assertCount(count($permissionNames), $permissionIds);
        $role->permissions()->syncWithoutDetaching($permissionIds);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function installation(): ModuleInstallation
    {
        return ModuleInstallation::query()
            ->where('sub_core_key', 'pet-care-clinic')
            ->where('module_key', 'consultations')
            ->firstOrFail();
    }

    private function renderedForm(string $html, string $id): string
    {
        $matched = preg_match(
            '/<form(?=[^>]*\bid="'.preg_quote($id, '/').'")[^>]*>.*?<\/form>/s',
            $html,
            $matches,
        );
        $this->assertSame(1, $matched, "Expected rendered form [{$id}].");

        return $matches[0];
    }
}
