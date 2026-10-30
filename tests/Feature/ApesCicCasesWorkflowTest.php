<?php

namespace Tests\Feature;

use App\Contracts\ModuleRegistry;
use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Plugins\Cases\Models\CaseUpdate;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Cases\Notifications\ApesCicCaseUpdatedNotification;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\Tickets\Http\Controllers\TicketController;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ApesCicCasesWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_service_user_can_create_and_view_only_their_own_apes_cic_cases(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $otherCase = $this->caseFor($otherOwner, ['title' => 'Private membership case']);

        $response = $this->actingAs($owner)->post(route('apes-cic.cases.store'), [
            'category' => 'formal_complaint',
            'sub_category' => 'service_complaint',
            'priority' => 'urgent',
            'title' => 'Service complaint',
            'details' => 'Please investigate this service experience.',
        ]);

        $case = ShelterCase::query()
            ->where('sub_core_key', 'apes-cic')
            ->where('user_id', $owner->id)
            ->firstOrFail();
        $response->assertRedirect(route('apes-cic.cases.show', $case))
            ->assertSessionHas('status', 'Case created.');
        $this->assertSame('open', $case->status);
        $this->assertNotNull($case->opened_at);
        $this->assertNull($case->pet_profile_id);

        $this->get(route('apes-cic.cases.index'))
            ->assertOk()
            ->assertSee('Service complaint')
            ->assertDontSee($otherCase->title);
        $this->get(route('apes-cic.cases.show', $otherCase))->assertForbidden();
    }

    public function test_ticket_and_case_indexes_hide_creation_forms_without_create_permission(): void
    {
        $viewer = User::factory()->create();
        $this->removeRolePermission(AuthorizationProfile::ROLE_SERVICE_USER, 'apes-cic.tickets.create');
        $this->removeRolePermission(AuthorizationProfile::ROLE_SERVICE_USER, 'apes-cic.cases.create');

        $this->actingAs($viewer->fresh())
            ->get(route('apes-cic.tickets.index'))
            ->assertOk()
            ->assertDontSee('"id":"action:create.apes-cic.tickets"', false)
            ->assertDontSee('action="'.route('apes-cic.tickets.store').'"', false);
        $this->get(route('apes-cic.cases.index'))
            ->assertOk()
            ->assertDontSee('Open a case')
            ->assertDontSee('action="'.route('apes-cic.cases.store').'"', false);
        $this->post(route('apes-cic.tickets.store'), [])->assertForbidden();
        $this->post(route('apes-cic.cases.store'), [])->assertForbidden();

        $role = Role::query()
            ->where('guard_name', 'web')
            ->where('name', AuthorizationProfile::ROLE_SERVICE_USER)
            ->firstOrFail();
        $role->permissions()->attach(Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', ['apes-cic.tickets.create', 'apes-cic.cases.create'])
            ->pluck('id'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $creator = User::factory()->create();
        $this->actingAs($creator)
            ->get(route('apes-cic.tickets.index'))
            ->assertOk()
            ->assertSee('Create ticket')
            ->assertSee('action="'.route('apes-cic.tickets.store').'"', false);
        $this->get(route('apes-cic.cases.index'))
            ->assertOk()
            ->assertSee('Open a case')
            ->assertSee('action="'.route('apes-cic.cases.store').'"', false);
    }

    public function test_apes_cic_ticket_presentation_and_internal_control_remain_unchanged(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $ticket = SupportTicket::create([
            'user_id' => $staff->id,
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => 'APES presentation fixture',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Existing APES Ticket copy must be retained.',
        ]);

        $this->actingAs($staff)
            ->get(route('apes-cic.tickets.index'))
            ->assertOk()
            ->assertSee('service-label apes-cic', false)
            ->assertSee('Organisational support tickets')
            ->assertSee('General organisational support for web, IT, HR, finance, operations and related needs.');
        $this->get(route('apes-cic.tickets.show', $ticket))
            ->assertSee('for="visibility"', false)
            ->assertSee('Internal staff only')
            ->assertSee('action="'.route('apes-cic.tickets.destroy', $ticket).'"', false);
    }

    public function test_apes_cic_ticket_creation_notification_serializes_the_explicit_open_status(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $recipient = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($owner)
            ->post(route('apes-cic.tickets.store'), [
                'service_area' => 'operations_facilities',
                'sub_category' => 'premises',
                'subject' => 'Explicit APES creation status',
                'priority' => 'high',
                'description' => 'The synchronous payload must report an open Ticket.',
            ])
            ->assertRedirect();

        $ticket = SupportTicket::query()
            ->where('sub_core_key', 'apes-cic')
            ->where('subject', 'Explicit APES creation status')
            ->firstOrFail();
        $this->assertSame('open', $ticket->status);
        Notification::assertSentTo(
            $recipient,
            TicketUpdatedNotification::class,
            function (TicketUpdatedNotification $notification) use ($recipient, $ticket): bool {
                $data = $notification->toArray($recipient);

                $this->assertSame('created', $data['event']);
                $this->assertSame('apes-cic', $data['service']);
                $this->assertSame('open', $data['status']);
                $this->assertSame(route('apes-cic.tickets.show', $ticket), $data['url']);

                return true;
            },
        );
    }

    public function test_ticket_reopening_requires_close_permission(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $closedAt = Carbon::parse('2026-08-01 09:00:00', 'UTC');
        $ticket = SupportTicket::query()->create([
            'user_id' => $owner->id,
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => 'Terminal ticket',
            'priority' => 'medium',
            'status' => 'resolved',
            'description' => 'Must not reopen without close permission.',
            'closed_at' => $closedAt,
        ]);
        $this->removeRolePermission(AuthorizationProfile::ROLE_STAFF, 'apes-cic.tickets.close');

        $this->actingAs($staff->fresh())
            ->put(route('apes-cic.tickets.update', $ticket), [
                'status' => 'open',
                'priority' => 'medium',
            ])
            ->assertForbidden();

        $ticket->refresh();
        $this->assertSame('resolved', $ticket->status);
        $this->assertTrue($ticket->closed_at->equalTo($closedAt));
    }

    public function test_ticket_terminal_relabel_preserves_its_first_terminal_timestamp(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $closedAt = Carbon::parse('2026-08-01 09:00:00', 'UTC');
        $ticket = SupportTicket::query()->create([
            'user_id' => $owner->id,
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => 'Terminal relabel ticket',
            'priority' => 'medium',
            'status' => 'resolved',
            'description' => 'Relabeling must retain the original terminal time.',
            'closed_at' => $closedAt,
        ]);

        Carbon::setTestNow('2026-08-10 12:00:00');
        try {
            $this->actingAs($staff)
                ->put(route('apes-cic.tickets.update', $ticket), [
                    'status' => 'closed',
                    'priority' => 'medium',
                ])
                ->assertRedirect(route('apes-cic.tickets.show', $ticket));
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertTrue($ticket->fresh()->closed_at->equalTo($closedAt));
    }

    public function test_cross_sub_core_case_binding_is_non_disclosing(): void
    {
        $owner = User::factory()->create();
        $shelterCase = $this->caseFor($owner, [
            'sub_core_key' => 'shelter-rescue',
            'case_type' => 'rescue',
            'category' => null,
            'title' => 'Shelter-only record',
        ]);

        $this->actingAs($owner)
            ->get(route('apes-cic.cases.show', $shelterCase))
            ->assertNotFound();
    }

    public function test_shelter_routes_cannot_list_view_or_update_apes_cic_cases(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $pet = PetProfile::create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_SHELTER,
            'name' => 'Shelter boundary pet',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);
        $shelterCase = $this->caseFor($owner, [
            'sub_core_key' => ShelterCase::SUB_CORE_SHELTER_RESCUE,
            'pet_profile_id' => $pet->id,
            'case_type' => 'rescue',
            'category' => null,
            'title' => 'Shelter-visible case',
        ]);
        $apesCase = $this->caseFor($owner, [
            'title' => 'APES route boundary case',
        ]);

        $this->actingAs($owner)
            ->get(route('shelter.cases.index'))
            ->assertOk()
            ->assertSee($shelterCase->title)
            ->assertDontSee($apesCase->title);
        $this->get(route('shelter.cases.show', $apesCase))->assertNotFound();
        $this->patch(route('shelter.cases.update', $apesCase), [
            'status' => 'closed',
            'details' => 'Must not cross the sub-core boundary.',
        ])->assertNotFound();

        $this->assertSame('open', $apesCase->fresh()->status);
        Notification::assertNothingSent();
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'shelter.case.updated',
            'auditable_type' => 'case',
            'auditable_id' => $apesCase->id,
        ]);
    }

    public function test_empty_case_patch_has_no_notification_or_audit_side_effects(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $case = $this->caseFor($owner);
        $originalUpdatedAt = $case->updated_at;

        $this->actingAs($owner)
            ->patchJson(route('apes-cic.cases.update', $case), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('case');

        $this->assertTrue($case->fresh()->updated_at->equalTo($originalUpdatedAt));
        Notification::assertNothingSent();
        $this->assertSame(0, AuditLog::query()
            ->where('event', 'apes_cic.case.updated')
            ->where('auditable_type', 'case')
            ->where('auditable_id', $case->id)
            ->count());
    }

    public function test_unchanged_full_case_form_has_no_side_effects(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $case = $this->caseFor($owner);
        $originalUpdatedAt = $case->updated_at;

        $this->actingAs($staff)->patchJson(
            route('apes-cic.cases.update', $case),
            [
                'category' => 'general_escalated',
                'sub_category' => 'escalated_from_ticket',
                'priority' => 'medium',
                'status' => 'open',
                'assigned_to' => null,
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('case');

        $this->assertTrue($case->fresh()->updated_at->equalTo($originalUpdatedAt));
        Notification::assertNothingSent();
        $this->assertSame(0, AuditLog::query()
            ->where('event', 'apes_cic.case.updated')
            ->where('auditable_type', 'case')
            ->where('auditable_id', $case->id)
            ->count());
    }

    public function test_ticket_assignment_rejects_eligible_staff_without_instance_view_permission(): void
    {
        $owner = User::factory()->create();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $candidate = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $staffRole = Role::query()
            ->where('guard_name', 'web')
            ->where('name', AuthorizationProfile::ROLE_STAFF)
            ->firstOrFail();
        $viewAll = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'apes-cic.tickets.view-all')
            ->firstOrFail();
        $staffRole->permissions()->detach($viewAll->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $ticket = SupportTicket::create([
            'user_id' => $owner->id,
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => 'Assignment visibility boundary',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'The assignee must be able to view this ticket.',
        ]);

        $this->actingAs($actor)
            ->put(route('apes-cic.tickets.update', $ticket), [
                'assigned_to' => $candidate->id,
            ])
            ->assertSessionHasErrors('assigned_to');

        $this->assertNull($ticket->fresh()->assigned_to);
    }

    public function test_record_policy_fails_closed_when_its_module_is_disabled(): void
    {
        $owner = User::factory()->create();
        $case = $this->caseFor($owner);
        $this->actingAs($owner);

        $this->assertTrue(Gate::allows('view', $case));
        ModuleInstallation::query()
            ->where('sub_core_key', ShelterCase::SUB_CORE_APES_CIC)
            ->where('module_key', 'cases')
            ->firstOrFail()
            ->forceFill(['enabled' => false, 'disabled_at' => now()])
            ->save();

        $this->assertFalse(Gate::allows('view', $case));
    }

    public function test_ticket_controller_accepts_the_shipped_shelter_route_instance(): void
    {
        Route::get('/_test/instance-tickets', [TicketController::class, 'index'])
            ->middleware('web')
            ->defaults('subCoreKey', 'shelter-rescue')
            ->defaults('moduleKey', 'tickets');

        $this->actingAs(User::factory()->create())
            ->get('/_test/instance-tickets')
            ->assertOk();
    }

    public function test_owner_updates_are_public_and_internal_staff_updates_stay_private(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $case = $this->caseFor($owner);

        $this->actingAs($owner)->post(
            route('apes-cic.cases.updates.store', $case),
            ['body' => 'Owner progress note', 'visibility' => 'internal'],
        )->assertRedirect(route('apes-cic.cases.show', $case));

        $this->assertDatabaseHas('case_updates', [
            'shelter_case_id' => $case->id,
            'body' => 'Owner progress note',
            'visibility' => CaseUpdate::VISIBILITY_PUBLIC,
        ]);

        $this->actingAs($staff)->post(
            route('apes-cic.cases.updates.store', $case),
            ['body' => 'Internal safeguarding note', 'visibility' => 'internal'],
        )->assertRedirect(route('apes-cic.cases.show', $case));

        $this->actingAs($owner)
            ->get(route('apes-cic.cases.show', $case))
            ->assertOk()
            ->assertSee('Owner progress note')
            ->assertDontSee('Internal safeguarding note');
    }

    public function test_staff_close_resolve_and_reopen_transitions_manage_terminal_timestamps(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $case = $this->caseFor($owner);

        $this->actingAs($staff)->patch(route('apes-cic.cases.update', $case), [
            'category' => 'welfare_concern',
            'sub_category' => 'animal_welfare',
            'priority' => 'high',
            'status' => 'resolved',
        ])->assertRedirect(route('apes-cic.cases.show', $case));

        $this->assertNotNull($case->fresh()->resolved_at);
        $this->assertNull($case->fresh()->closed_at);
        $resolvedAt = $case->fresh()->resolved_at;

        $this->patch(route('apes-cic.cases.update', $case), [
            'category' => 'welfare_concern',
            'sub_category' => 'animal_welfare',
            'priority' => 'high',
            'status' => 'closed',
        ])->assertRedirect(route('apes-cic.cases.show', $case));
        $this->assertNotNull($case->fresh()->closed_at);
        $this->assertTrue($case->fresh()->resolved_at->equalTo($resolvedAt));

        $this->post(
            route('apes-cic.cases.updates.store', $case),
            ['body' => 'Must reopen first.', 'visibility' => 'public'],
        )->assertSessionHasErrors('body');

        $this->patch(route('apes-cic.cases.update', $case), [
            'category' => 'welfare_concern',
            'sub_category' => 'animal_welfare',
            'priority' => 'medium',
            'status' => 'in_progress',
        ])->assertRedirect(route('apes-cic.cases.show', $case));

        $this->assertNull($case->fresh()->resolved_at);
        $this->assertNull($case->fresh()->closed_at);
    }

    public function test_closed_case_resolve_transition_preserves_first_terminal_time_in_analytics(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $openedAt = Carbon::parse('2026-08-01 09:00:00', 'UTC');
        $closedAt = Carbon::parse('2026-08-02 10:00:00', 'UTC');
        $case = $this->caseFor($owner, [
            'status' => 'closed',
            'opened_at' => $openedAt,
            'resolved_at' => null,
            'closed_at' => $closedAt,
        ]);
        $registry = app(ModuleRegistry::class);
        $instance = $registry->instance('apes-cic', 'cases');

        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00', 'UTC'));
        try {
            $this->actingAs($staff)->patch(route('apes-cic.cases.update', $case), [
                'category' => 'general_escalated',
                'sub_category' => 'escalated_from_ticket',
                'priority' => 'medium',
                'status' => 'resolved',
            ])->assertRedirect(route('apes-cic.cases.show', $case));

            $case->refresh();
            $snapshot = app($instance->analyticsProviderClass())->snapshot(
                $instance,
                Carbon::parse('2026-08-01 00:00:00', 'UTC'),
                Carbon::parse('2026-08-11 00:00:00', 'UTC'),
                'UTC',
            );

            $this->assertSame([
                'status' => 'resolved',
                'resolved_at' => '2026-08-02 10:00:00',
                'closed_at' => null,
                'closed_per_day' => ['2026-08-02' => 1],
                'median_closure_minutes' => 1500.0,
            ], [
                'status' => $case->status,
                'resolved_at' => $case->resolved_at?->toDateTimeString(),
                'closed_at' => $case->closed_at?->toDateTimeString(),
                'closed_per_day' => $snapshot->closedPerDay,
                'median_closure_minutes' => $snapshot->medianClosureMinutes,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_terminal_case_metadata_edits_preserve_timestamps_without_close_permission(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $resolvedAt = Carbon::parse('2026-08-01 09:00:00');
        $closedAt = Carbon::parse('2026-08-02 10:00:00');
        $resolvedCase = $this->caseFor($owner, [
            'status' => 'resolved',
            'resolved_at' => $resolvedAt,
        ]);
        $closedCase = $this->caseFor($owner, [
            'status' => 'closed',
            'resolved_at' => $resolvedAt,
            'closed_at' => $closedAt,
        ]);
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'apes-cic.cases.close',
        );

        Carbon::setTestNow('2026-08-10 12:00:00');
        try {
            $this->actingAs($staff)->patch(
                route('apes-cic.cases.update', $resolvedCase),
                [
                    'category' => 'membership_casework',
                    'sub_category' => 'member_dispute',
                    'priority' => 'high',
                    'status' => 'resolved',
                ],
            )->assertRedirect(route('apes-cic.cases.show', $resolvedCase));
            $this->patch(route('apes-cic.cases.update', $closedCase), [
                'category' => 'formal_complaint',
                'sub_category' => 'service_complaint',
                'priority' => 'urgent',
                'status' => 'closed',
            ])->assertRedirect(route('apes-cic.cases.show', $closedCase));
        } finally {
            Carbon::setTestNow();
        }

        $resolvedCase->refresh();
        $this->assertSame('membership_casework', $resolvedCase->category);
        $this->assertSame('high', $resolvedCase->priority);
        $this->assertTrue($resolvedCase->resolved_at->equalTo($resolvedAt));
        $this->assertNull($resolvedCase->closed_at);

        $closedCase->refresh();
        $this->assertSame('formal_complaint', $closedCase->category);
        $this->assertSame('urgent', $closedCase->priority);
        $this->assertTrue($closedCase->resolved_at->equalTo($resolvedAt));
        $this->assertTrue($closedCase->closed_at->equalTo($closedAt));
    }

    public function test_ticket_staff_can_choose_public_or_internal_reply_visibility(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $ticket = SupportTicket::create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => 'Shared reply visibility',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Visibility regression coverage.',
        ]);

        $this->actingAs($staff)->put(route('apes-cic.tickets.update', $ticket), [
            'message' => 'Public staff response',
            'visibility' => 'public',
        ])->assertRedirect(route('apes-cic.tickets.show', $ticket));
        $this->put(route('apes-cic.tickets.update', $ticket), [
            'message' => 'Internal staff response',
            'visibility' => 'internal',
        ])->assertRedirect(route('apes-cic.tickets.show', $ticket));

        $this->actingAs($owner)
            ->get(route('apes-cic.tickets.show', $ticket))
            ->assertSee('Public staff response')
            ->assertDontSee('Internal staff response');
    }

    public function test_public_updates_advance_activity_without_internal_notes_reordering_owner_activity(): void
    {
        Carbon::setTestNow('2026-08-10 09:00:00');
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $ticket = SupportTicket::create([
            'user_id' => $owner->id,
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => 'Activity timestamp ticket',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Public replies should advance visible activity.',
        ]);
        $case = $this->caseFor($owner, ['title' => 'Activity timestamp case']);

        Carbon::setTestNow('2026-08-10 10:00:00');
        $this->actingAs($owner)->put(route('apes-cic.tickets.update', $ticket), [
            'message' => 'Public ticket activity',
            'visibility' => 'public',
        ])->assertRedirect();
        $this->post(route('apes-cic.cases.updates.store', $case), [
            'body' => 'Public case activity',
            'visibility' => 'public',
        ])->assertRedirect();

        $this->assertTrue($ticket->fresh()->updated_at->equalTo(now()));
        $this->assertTrue($case->fresh()->updated_at->equalTo(now()));

        Carbon::setTestNow('2026-08-10 11:00:00');
        $this->actingAs($staff)->put(route('apes-cic.tickets.update', $ticket), [
            'status' => 'open',
            'priority' => 'medium',
            'assigned_to' => null,
            'message' => 'Internal ticket activity',
            'visibility' => 'internal',
        ])->assertRedirect();
        $this->post(route('apes-cic.cases.updates.store', $case), [
            'body' => 'Internal case activity',
            'visibility' => 'internal',
        ])->assertRedirect();

        $this->assertTrue($ticket->fresh()->updated_at->equalTo(
            Carbon::parse('2026-08-10 10:00:00'),
        ));
        $this->assertTrue($case->fresh()->updated_at->equalTo(
            Carbon::parse('2026-08-10 10:00:00'),
        ));
    }

    public function test_internal_ticket_notes_with_record_changes_notify_the_owner_without_disclosing_the_note(): void
    {
        Notification::fake();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $candidate = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $changes = [
            'status' => ['status' => 'in_progress', 'priority' => 'medium', 'assigned_to' => null],
            'priority' => ['status' => 'open', 'priority' => 'high', 'assigned_to' => null],
            'assignment' => ['status' => 'open', 'priority' => 'medium', 'assigned_to' => $candidate->id],
        ];

        foreach ($changes as $label => $metadata) {
            $owner = User::factory()->create();
            $ticket = SupportTicket::create([
                'user_id' => $owner->id,
                'assigned_to' => null,
                'service_area' => 'operations_facilities',
                'sub_category' => 'premises',
                'subject' => "Internal note with {$label} change",
                'priority' => 'medium',
                'status' => 'open',
                'description' => 'The owner must receive safe record-change metadata.',
            ]);
            $note = "Private {$label} implementation note";

            $this->actingAs($actor)->put(
                route('apes-cic.tickets.update', $ticket),
                [
                    ...$metadata,
                    'message' => $note,
                    'visibility' => 'internal',
                ],
            )->assertRedirect(route('apes-cic.tickets.show', $ticket));

            $this->assertDatabaseHas('support_ticket_messages', [
                'support_ticket_id' => $ticket->id,
                'message' => $note,
                'is_staff_note' => true,
            ]);
            Notification::assertSentToTimes(
                $owner,
                TicketUpdatedNotification::class,
                1,
            );
            Notification::assertSentTo(
                $owner,
                TicketUpdatedNotification::class,
                function (TicketUpdatedNotification $notification) use ($note, $owner): bool {
                    return ! str_contains(
                        json_encode($notification->toArray($owner), JSON_THROW_ON_ERROR),
                        $note,
                    );
                },
            );
        }
    }

    public function test_same_terminal_ticket_status_preserves_closed_timestamp(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $closedAt = Carbon::parse('2026-08-01 09:00:00');
        $ticket = SupportTicket::create([
            'user_id' => $owner->id,
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => 'Preserve terminal ticket timestamp',
            'priority' => 'medium',
            'status' => 'resolved',
            'description' => 'A priority edit must not rewrite closure time.',
            'closed_at' => $closedAt,
        ]);
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'apes-cic.tickets.close',
        );

        Carbon::setTestNow('2026-08-10 12:00:00');
        try {
            $this->actingAs($staff)->put(
                route('apes-cic.tickets.update', $ticket),
                [
                    'status' => 'resolved',
                    'priority' => 'high',
                ],
            )->assertRedirect(route('apes-cic.tickets.show', $ticket));
        } finally {
            Carbon::setTestNow();
        }

        $ticket->refresh();
        $this->assertSame('high', $ticket->priority);
        $this->assertTrue($ticket->closed_at->equalTo($closedAt));
    }

    public function test_unchanged_empty_ticket_form_has_no_side_effects(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $ticket = SupportTicket::create([
            'user_id' => $owner->id,
            'assigned_to' => null,
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => 'Reject unchanged ticket form',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'No-op forms must not create activity.',
        ]);
        $originalUpdatedAt = $ticket->updated_at;

        $this->actingAs($staff)->putJson(
            route('apes-cic.tickets.update', $ticket),
            [
                'status' => 'open',
                'priority' => 'medium',
                'assigned_to' => null,
                'message' => '',
                'visibility' => 'internal',
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('ticket');

        $this->assertTrue($ticket->fresh()->updated_at->equalTo($originalUpdatedAt));
        Notification::assertNothingSent();
        $this->assertSame(0, AuditLog::query()
            ->where('event', 'apes_cic.ticket.updated')
            ->where('auditable_type', 'support_ticket')
            ->where('auditable_id', $ticket->id)
            ->count());
    }

    public function test_public_and_internal_notifications_use_eligible_recipients_without_body_disclosure(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $eligibleStaff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $suspendedStaff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create(['suspended_at' => now()]);
        $ticket = SupportTicket::create([
            'user_id' => $owner->id,
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => 'Notification privacy ticket',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Description must not enter audit metadata.',
        ]);
        $case = $this->caseFor($owner, [
            'title' => 'Notification privacy case',
            'details' => 'Details must not enter audit metadata.',
        ]);

        foreach ([
            ['visibility' => 'internal', 'message' => 'Private ticket note'],
            ['visibility' => 'public', 'message' => 'Public ticket reply'],
        ] as $reply) {
            $this->actingAs($actor)->put(
                route('apes-cic.tickets.update', $ticket),
                $reply,
            )->assertRedirect();
        }
        foreach ([
            ['visibility' => 'internal', 'body' => 'Private case note'],
            ['visibility' => 'public', 'body' => 'Public case update'],
        ] as $update) {
            $this->post(
                route('apes-cic.cases.updates.store', $case),
                $update,
            )->assertRedirect();
        }
        $owner->forceFill(['suspended_at' => now()])->save();
        $this->post(route('apes-cic.cases.updates.store', $case), [
            'visibility' => 'public',
            'body' => 'Update while owner is inaccessible',
        ])->assertRedirect();

        Notification::assertSentToTimes(
            $eligibleStaff,
            TicketUpdatedNotification::class,
            2,
        );
        Notification::assertSentToTimes(
            $eligibleStaff,
            ApesCicCaseUpdatedNotification::class,
            3,
        );
        Notification::assertSentToTimes(
            $owner,
            TicketUpdatedNotification::class,
            1,
        );
        Notification::assertSentToTimes(
            $owner,
            ApesCicCaseUpdatedNotification::class,
            1,
        );
        Notification::assertNothingSentTo($actor);
        Notification::assertNothingSentTo($suspendedStaff);
        Notification::assertSentTo(
            $owner,
            TicketUpdatedNotification::class,
            function (TicketUpdatedNotification $notification) use ($owner, $ticket): bool {
                $data = $notification->toArray($owner);
                $payload = json_encode($data);

                return $data['url'] === route('apes-cic.tickets.show', $ticket)
                    && ! str_contains($payload, 'Private ticket note')
                    && ! str_contains($payload, 'Public ticket reply');
            },
        );
        Notification::assertSentTo(
            $owner,
            ApesCicCaseUpdatedNotification::class,
            function (ApesCicCaseUpdatedNotification $notification) use ($owner, $case): bool {
                $data = $notification->toArray($owner);
                $payload = json_encode($data);

                return $data['url'] === route('apes-cic.cases.show', $case)
                    && ! str_contains($payload, 'Private case note')
                    && ! str_contains($payload, 'Public case update');
            },
        );

        foreach (AuditLog::query()
            ->whereIn('event', [
                'apes_cic.ticket.updated',
                'apes_cic.case.update_added',
            ])
            ->get() as $audit) {
            $context = json_encode($audit->context);
            $this->assertSame('apes-cic', $audit->context['sub_core_key']);
            $this->assertContains($audit->context['module_key'], ['tickets', 'cases']);
            $this->assertStringNotContainsString('Private', $context);
            $this->assertStringNotContainsString('Public', $context);
            $this->assertStringNotContainsString('Description', $context);
            $this->assertStringNotContainsString('Details', $context);
        }
    }

    public function test_internal_case_update_preserves_dual_role_owner_staff_notification(): void
    {
        Notification::fake();
        $owner = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $case = $this->caseFor($owner, [
            'title' => 'APES dual-role notification case',
        ]);

        $this->actingAs($actor)
            ->post(route('apes-cic.cases.updates.store', $case), [
                'visibility' => 'internal',
                'body' => 'APES internal staff update.',
            ])->assertRedirect(route('apes-cic.cases.show', $case));

        Notification::assertSentTo(
            $owner,
            ApesCicCaseUpdatedNotification::class,
        );
        Notification::assertNothingSentTo($actor);
    }

    public function test_case_workflow_accepts_every_supported_category_priority_and_status(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        foreach ([
            ['data_access_request', 'copy_of_data', 'myapes_account'],
            ['privacy_request', 'rectification', null],
            ['formal_complaint', 'service_complaint', null],
            ['membership_casework', 'member_dispute', null],
            ['welfare_concern', 'animal_welfare', null],
            ['operations_governance', 'operational_matter', null],
            ['general_escalated', 'escalated_from_ticket', null],
            ['data_protection_enquiry', 'gdpr_general', null],
        ] as [$category, $sub, $website]) {
            $payload = [
                'category' => $category,
                'sub_category' => $sub,
                'priority' => 'medium',
                'title' => "{$category} category case",
            ];
            if ($website !== null) {
                $payload['affected_website_key'] = $website;
            }
            $this->actingAs($owner)->post(route('apes-cic.cases.store'), $payload)->assertRedirect();
        }
        foreach (['low', 'medium', 'high', 'urgent'] as $priority) {
            $this->post(route('apes-cic.cases.store'), [
                'category' => 'general_escalated',
                'sub_category' => 'escalated_from_ticket',
                'priority' => $priority,
                'title' => "{$priority} priority case",
            ])->assertRedirect();
        }
        foreach (['open', 'in_progress', 'waiting_on_user', 'resolved', 'closed'] as $status) {
            $case = $this->caseFor($owner, ['title' => "{$status} status case"]);
            $this->actingAs($staff)->patch(route('apes-cic.cases.update', $case), [
                'category' => 'general_escalated',
                'sub_category' => 'escalated_from_ticket',
                'priority' => 'medium',
                'status' => $status,
            ])->assertRedirect();
            $this->assertSame($status, $case->fresh()->status);
        }
    }

    public function test_case_owner_cannot_mutate_staff_controlled_metadata(): void
    {
        $owner = User::factory()->create();
        $case = $this->caseFor($owner);

        $this->actingAs($owner)->patch(route('apes-cic.cases.update', $case), [
            'category' => 'welfare_concern',
            'sub_category' => 'animal_welfare',
            'priority' => 'urgent',
            'status' => 'in_progress',
        ])->assertForbidden();

        $case->refresh();
        $this->assertSame('general_escalated', $case->category);
        $this->assertSame('medium', $case->priority);
        $this->assertSame('open', $case->status);
    }

    public function test_case_metadata_requires_update_all_permission(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $case = $this->caseFor($owner);
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'apes-cic.cases.update-all',
        );

        $this->actingAs($staff)->patch(route('apes-cic.cases.update', $case), [
            'category' => 'operations_governance',
            'sub_category' => 'operational_matter',
            'priority' => 'high',
            'status' => 'in_progress',
        ])->assertForbidden();
        $this->assertSame('open', $case->fresh()->status);
    }

    public function test_case_assignment_requires_assign_permission(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $candidate = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $case = $this->caseFor($owner);
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'apes-cic.cases.assign',
        );

        $this->actingAs($staff)->patch(route('apes-cic.cases.update', $case), [
            'assigned_to' => $candidate->id,
        ])->assertForbidden();
        $this->assertNull($case->fresh()->assigned_to);
    }

    public function test_case_terminal_transitions_require_close_permission(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $case = $this->caseFor($owner);
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'apes-cic.cases.close',
        );

        $this->actingAs($staff)->patch(route('apes-cic.cases.update', $case), [
            'category' => 'general_escalated',
            'sub_category' => 'escalated_from_ticket',
            'priority' => 'medium',
            'status' => 'resolved',
        ])->assertForbidden();
        $this->assertSame('open', $case->fresh()->status);
    }

    public function test_case_deletion_requires_delete_permission(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $case = $this->caseFor($owner);
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'apes-cic.cases.delete',
        );

        $this->actingAs($staff)
            ->delete(route('apes-cic.cases.destroy', $case))
            ->assertForbidden();
        $this->assertModelExists($case);

        $this->actingAs($administrator)
            ->delete(route('apes-cic.cases.destroy', $case))
            ->assertRedirect(route('apes-cic.cases.index'));
        $this->assertModelMissing($case);
    }

    private function caseFor(User $owner, array $attributes = []): ShelterCase
    {
        return ShelterCase::create(array_merge([
            'sub_core_key' => 'apes-cic',
            'pet_profile_id' => null,
            'user_id' => $owner->id,
            'assigned_to' => null,
            'case_type' => null,
            'category' => 'general_escalated',
            'sub_category' => 'escalated_from_ticket',
            'priority' => 'medium',
            'status' => 'open',
            'title' => 'General APES CIC case',
            'details' => 'Initial case details.',
            'opened_at' => now(),
        ], $attributes));
    }

    private function removeRolePermission(string $roleName, string $permissionName): void
    {
        $role = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $roleName)
            ->firstOrFail();
        $permission = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', $permissionName)
            ->firstOrFail();
        $role->permissions()->detach($permission->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
