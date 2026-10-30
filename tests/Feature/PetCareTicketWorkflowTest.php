<?php

namespace Tests\Feature;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Services\AuthorizationProfile;
use App\Services\AuthorizationRoleMaterializer;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Plugins\Tickets\TicketServiceConfiguration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PetCareTicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_petcare_ticket_routes_and_service_configuration_are_exact(): void
    {
        $service = app(TicketServiceConfiguration::class)->for('pet-care-clinic');

        $this->assertSame('APES Pet Care Clinic', $service->serviceName);
        $this->assertSame('petcare.tickets', $service->routePrefix);
        $this->assertSame('petcare.ticket', $service->auditPrefix);
        $this->assertSame('apes-petcare', $service->presentationClass);
        $this->assertSame([
            'appointment',
            'consultation',
            'prescription',
            'billing',
            'follow_up',
            'other',
        ], $service->serviceAreas);
        $this->assertFalse($service->supportsDelete);

        foreach (['index', 'store', 'show', 'update'] as $action) {
            $route = Route::getRoutes()->getByName("petcare.tickets.{$action}");
            $this->assertNotNull($route);
            $this->assertSame('pet-care-clinic', $route->defaults['subCoreKey'] ?? null);
            $this->assertSame('tickets', $route->defaults['moduleKey'] ?? null);
            $this->assertContains(
                'plugin.enabled:pet-care-clinic,tickets',
                $route->gatherMiddleware(),
            );
            $this->assertContains(
                'service.selected:pet-care-clinic',
                $route->gatherMiddleware(),
            );
        }
        $this->assertFalse(Route::getRoutes()->hasNamedRoute('petcare.tickets.destroy'));
    }

    public function test_petcare_ticket_routes_validate_the_petcare_service_areas_and_never_expose_deletion(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $recipient = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($owner)
            ->get(route('petcare.tickets.index'))
            ->assertOk()
            ->assertSeeInOrder([
                '<option value="appointment" >Appointment</option>',
                '<option value="consultation" >Consultation</option>',
                '<option value="prescription" >Prescription</option>',
                '<option value="billing" >Billing</option>',
                '<option value="follow_up" >Follow-Up</option>',
                '<option value="other" >Other</option>',
            ], false);

        foreach (['operations', 'rescue'] as $foreignServiceArea) {
            $this->post(route('petcare.tickets.store'), [
                'service_area' => $foreignServiceArea,
                'subject' => 'Invalid Pet Care area',
                'priority' => 'medium',
                'description' => 'This must be rejected for Pet Care Tickets.',
            ])->assertSessionHasErrors('service_area');
        }

        foreach ([
            'appointment',
            'consultation',
            'prescription',
            'billing',
            'follow_up',
            'other',
        ] as $serviceArea) {
            $this->post(route('petcare.tickets.store'), [
                'service_area' => $serviceArea,
                'subject' => "Pet Care {$serviceArea} request",
                'priority' => 'high',
                'description' => 'A valid Pet Care Ticket.',
            ])->assertRedirect();
        }

        $ticket = SupportTicket::query()
            ->where('sub_core_key', 'pet-care-clinic')
            ->where('service_area', 'appointment')
            ->firstOrFail();
        $this->assertSame(6, SupportTicket::query()
            ->where('sub_core_key', 'pet-care-clinic')
            ->count());
        $this->assertSame('appointment', $ticket->service_area);
        $this->assertSame($owner->id, $ticket->user_id);
        $this->assertSame('pet-care-clinic', $ticket->sub_core_key);
        $this->assertSame('open', $ticket->status);
        $audit = AuditLog::query()
            ->where('event', 'petcare.ticket.created')
            ->where('auditable_id', $ticket->id)
            ->firstOrFail();
        $this->assertSame('pet-care-clinic', $audit->context['sub_core_key']);
        $this->assertSame('tickets', $audit->context['module_key']);
        Notification::assertSentTo(
            $recipient,
            TicketUpdatedNotification::class,
            function (TicketUpdatedNotification $notification) use ($recipient, $ticket): bool {
                $data = $notification->toArray($recipient);

                return $data['event'] === 'created'
                    && $data['service'] === 'pet-care-clinic'
                    && $data['status'] === 'open'
                    && $data['url'] === route('petcare.tickets.show', $ticket);
            },
        );
        $this->assertFalse(app('router')->getRoutes()->hasNamedRoute(
            'petcare.tickets.destroy',
        ));
        $this->get(route('petcare.tickets.show', $ticket))
            ->assertDontSee('Delete ticket');
    }

    public function test_petcare_ticket_routes_return_404_for_apes_and_shelter_records_before_authorization(): void
    {
        $owner = User::factory()->create();
        foreach ([
            ['apes-cic', 'operations', 'APES-only ticket'],
            ['shelter-rescue', 'rescue', 'Shelter-only ticket'],
        ] as [$subCoreKey, $serviceArea, $subject]) {
            $ticket = SupportTicket::create([
                'sub_core_key' => $subCoreKey,
                'user_id' => $owner->id,
                'service_area' => $serviceArea,
                'subject' => $subject,
                'priority' => 'medium',
                'status' => 'open',
                'description' => 'Not a Pet Care record.',
            ]);

            $this->actingAs($owner)
                ->get(route('petcare.tickets.show', $ticket))
                ->assertNotFound();
            $this->put(route('petcare.tickets.update', $ticket), [
                'message' => 'Must never be written.',
            ])->assertNotFound();
            $this->assertSame('open', $ticket->fresh()->status);
            $this->assertSame(0, $ticket->messages()->count());
        }
    }

    public function test_ticket_visibility_requires_an_exact_instance_permission_for_owners_and_staff(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $ownerTicket = $this->ticketFor($owner, 'Owner ticket');
        $staffTicket = $this->ticketFor($staff, 'Staff-owned ticket');

        $this->removeRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'pet-care-clinic.tickets.view-own',
        );
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'pet-care-clinic.tickets.view-own',
        );
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'pet-care-clinic.tickets.view-all',
        );

        $this->actingAs($owner->fresh());
        $this->assertSame([], SupportTicket::query()
            ->forSubCore('pet-care-clinic')
            ->visibleTo($owner->fresh(), 'pet-care-clinic')
            ->pluck('id')
            ->all());
        $this->actingAs($staff->fresh());
        $this->assertSame([], SupportTicket::query()
            ->forSubCore('pet-care-clinic')
            ->visibleTo($staff->fresh(), 'pet-care-clinic')
            ->pluck('id')
            ->all());
        $this->assertNotSame($ownerTicket->id, $staffTicket->id);
    }

    public function test_petcare_owner_and_exact_staff_visibility_are_isolated_by_instance(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $owned = $this->ticketFor($owner, 'Owned Pet Care Ticket');
        $other = $this->ticketFor($otherOwner, 'Other Pet Care Ticket');
        $apesTicket = SupportTicket::create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'service_area' => 'operations',
            'subject' => 'Foreign APES owner ticket',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Must never render in Pet Care indexes.',
        ]);
        $shelterTicket = SupportTicket::create([
            'sub_core_key' => 'shelter-rescue',
            'user_id' => $owner->id,
            'service_area' => 'rescue',
            'subject' => 'Foreign Shelter owner ticket',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Must never render in Pet Care indexes.',
        ]);

        $this->actingAs($owner)
            ->get(route('petcare.tickets.index'))
            ->assertOk()
            ->assertSee($owned->subject)
            ->assertDontSee($other->subject)
            ->assertDontSee($apesTicket->subject)
            ->assertDontSee($shelterTicket->subject);
        $this->get(route('petcare.tickets.show', $other))->assertForbidden();

        $this->actingAs($staff)
            ->get(route('petcare.tickets.index'))
            ->assertOk()
            ->assertSee($owned->subject)
            ->assertSee($other->subject)
            ->assertDontSee($apesTicket->subject)
            ->assertDontSee($shelterTicket->subject);
    }

    public function test_apes_shelter_and_petcare_ticket_view_permissions_do_not_cross_grant_access(): void
    {
        $owner = User::factory()->create();
        SupportTicket::create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'service_area' => 'operations',
            'subject' => 'APES permission boundary',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'APES Ticket fixture.',
        ]);
        $this->ticketFor($owner, 'Pet Care permission boundary');
        SupportTicket::create([
            'sub_core_key' => 'shelter-rescue',
            'user_id' => $owner->id,
            'service_area' => 'rescue',
            'subject' => 'Shelter permission boundary',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Shelter Ticket fixture.',
        ]);

        $this->removeRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'pet-care-clinic.tickets.view-own',
        );
        $this->actingAs($owner->fresh())
            ->get(route('apes-cic.tickets.index'))
            ->assertOk();
        $this->get(route('petcare.tickets.index'))->assertForbidden();

        $this->actingAs($owner->fresh())
            ->get(route('shelter.tickets.index'))
            ->assertOk();
        $this->get(route('petcare.tickets.index'))->assertForbidden();

        $this->grantRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'pet-care-clinic.tickets.view-own',
        );
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'apes-cic.tickets.view-own',
        );
        $this->actingAs($owner->fresh())
            ->get(route('petcare.tickets.index'))
            ->assertOk();
        $this->get(route('apes-cic.tickets.index'))->assertForbidden();
    }

    public function test_petcare_cross_sub_core_ticket_update_is_a_non_disclosing_404(): void
    {
        $owner = User::factory()->create();
        $apesTicket = SupportTicket::create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'service_area' => 'operations',
            'subject' => 'Cross-sub-core update',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'APES Ticket fixture.',
        ]);

        $this->actingAs($owner)
            ->put(route('petcare.tickets.update', $apesTicket), [
                'message' => 'Must never be written.',
            ])
            ->assertNotFound();
        $this->assertSame('open', $apesTicket->fresh()->status);
        $this->assertSame(0, $apesTicket->messages()->count());
    }

    public function test_petcare_assignment_requires_an_eligible_actor_with_assign_and_an_exact_view_all_candidate(): void
    {
        $owner = User::factory()->create();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $candidate = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $ticket = $this->ticketFor($owner, 'Pet Care assignment Ticket');

        $this->actingAs($actor)
            ->put(route('petcare.tickets.update', $ticket), [
                'assigned_to' => $candidate->id,
            ])
            ->assertRedirect(route('petcare.tickets.show', $ticket));
        $this->assertSame($candidate->id, $ticket->fresh()->assigned_to);

        $adminActor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'pet-care-clinic.tickets.view-all',
        );
        $this->actingAs($adminActor)
            ->put(route('petcare.tickets.update', $ticket), [
                'assigned_to' => $candidate->id,
            ])
            ->assertSessionHasErrors('assigned_to');
        $this->assertSame($candidate->id, $ticket->fresh()->assigned_to);
    }

    public function test_shared_ticket_forms_keep_assignment_intent_separate_when_an_assignee_becomes_ineligible(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $apesAssignee = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $petcareAssignee = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $apesTicket = SupportTicket::create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'service_area' => 'operations',
            'subject' => 'APES assignment intent',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Unrelated saves must preserve the assignee.',
        ]);
        $petcareTicket = $this->ticketFor($owner, 'Pet Care assignment intent');

        foreach ([
            [$apesTicket, $apesAssignee, 'apes-cic.tickets'],
            [$petcareTicket, $petcareAssignee, 'petcare.tickets'],
        ] as [$ticket, $assignee, $routePrefix]) {
            $this->actingAs($actor)
                ->put(route($routePrefix.'.update', $ticket), [
                    'assigned_to' => $assignee->id,
                ])
                ->assertRedirect(route($routePrefix.'.show', $ticket));
            $this->assertSame($assignee->id, $ticket->fresh()->assigned_to);
        }

        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'apes-cic.tickets.view-all',
        );
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'pet-care-clinic.tickets.view-all',
        );

        foreach ([
            [$apesTicket, $apesAssignee, 'apes-cic.tickets'],
            [$petcareTicket, $petcareAssignee, 'petcare.tickets'],
        ] as [$ticket, $assignee, $routePrefix]) {
            $response = $this->actingAs($actor->fresh())
                ->get(route($routePrefix.'.show', $ticket))
                ->assertOk();
            $workflowForm = $this->renderedForm(
                $response->getContent(),
                'ticket-workflow-form',
            );
            $assignmentForm = $this->renderedForm(
                $response->getContent(),
                'ticket-assignment-form',
            );
            $this->assertStringContainsString('name="status"', $workflowForm);
            $this->assertStringContainsString('name="message"', $workflowForm);
            $this->assertStringNotContainsString('name="assigned_to"', $workflowForm);
            $this->assertStringContainsString('name="assigned_to"', $assignmentForm);
            $this->assertStringNotContainsString('name="status"', $assignmentForm);
            $this->assertStringNotContainsString('name="message"', $assignmentForm);

            $this->put(route($routePrefix.'.update', $ticket), [
                'status' => 'in_progress',
                'priority' => 'high',
            ])->assertRedirect(route($routePrefix.'.show', $ticket));
            $this->assertSame($assignee->id, $ticket->fresh()->assigned_to);
            $this->assertSame('high', $ticket->fresh()->priority);

            $this->put(route($routePrefix.'.update', $ticket), [
                'message' => 'This unrelated reply keeps the current assignee.',
                'visibility' => 'public',
            ])->assertRedirect(route($routePrefix.'.show', $ticket));
            $this->assertSame($assignee->id, $ticket->fresh()->assigned_to);

            $this->put(route($routePrefix.'.update', $ticket), [
                'assigned_to' => null,
            ])->assertRedirect(route($routePrefix.'.show', $ticket));
            $this->assertNull($ticket->fresh()->assigned_to);
        }
    }

    public function test_petcare_terminal_timestamp_and_no_op_semantics_match_apes_tickets(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $ticket = $this->ticketFor($owner, 'Pet Care terminal Ticket');

        $this->actingAs($staff)
            ->put(route('petcare.tickets.update', $ticket), [
                'status' => 'resolved',
                'priority' => 'medium',
            ])
            ->assertRedirect(route('petcare.tickets.show', $ticket));
        $firstClosedAt = $ticket->fresh()->closed_at;

        $this->put(route('petcare.tickets.update', $ticket), [
            'status' => 'open',
            'priority' => 'medium',
        ])->assertRedirect(route('petcare.tickets.show', $ticket));
        $this->assertNull($ticket->fresh()->closed_at);

        $this->travel(1)->minute();
        $this->put(route('petcare.tickets.update', $ticket), [
            'status' => 'closed',
            'priority' => 'medium',
        ])->assertRedirect(route('petcare.tickets.show', $ticket));
        $closedAt = $ticket->fresh()->closed_at;
        $this->assertNotNull($closedAt);
        $this->assertFalse($closedAt->equalTo($firstClosedAt));

        $this->put(route('petcare.tickets.update', $ticket), [
            'status' => 'closed',
            'priority' => 'medium',
        ])->assertSessionHasErrors('ticket');
        $this->assertTrue($ticket->fresh()->closed_at->equalTo($closedAt));
    }

    public function test_petcare_assignment_actor_must_be_eligible_staff_with_exact_assign(): void
    {
        $owner = User::factory()->create();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $candidate = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $ticket = $this->ticketFor($owner, 'Pet Care actor authorization Ticket');
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'pet-care-clinic.tickets.assign',
        );

        $this->actingAs($actor->fresh())
            ->put(route('petcare.tickets.update', $ticket), [
                'assigned_to' => $candidate->id,
            ])
            ->assertForbidden();
        $this->assertNull($ticket->fresh()->assigned_to);
    }

    public function test_petcare_public_ticket_recipients_are_exact_staff_or_owner_and_exclude_the_actor(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $recipient = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $suspended = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create(['suspended_at' => now(), 'suspension_reason' => 'test']);
        $permissionOnly = User::factory()->create();
        $permissionOnlyRole = \App\Core\Accounts\Role::query()->create([
            'name' => 'petcare-ticket-helper',
            'guard_name' => 'web',
            'is_protected' => false,
        ]);
        $permissionOnlyRole->permissions()->attach(Permission::query()
            ->where('name', 'pet-care-clinic.tickets.view-all')
            ->value('id'));
        app(AuthorizationRoleMaterializer::class)->grant(
            $permissionOnly,
            $permissionOnlyRole,
            RoleSource::SOURCE_LOCAL,
            actor: $actor,
        );
        $ticket = $this->ticketFor($owner, 'Pet Care recipient filtering Ticket');
        $body = 'Public update body remains private metadata.';

        $this->actingAs($actor)
            ->put(route('petcare.tickets.update', $ticket), [
                'message' => $body,
                'visibility' => 'public',
            ])
            ->assertRedirect(route('petcare.tickets.show', $ticket));

        $message = $ticket->messages()
            ->where('message', $body)
            ->firstOrFail();
        $this->assertSame($actor->id, $message->user_id);
        $this->assertFalse($message->is_staff_note);
        $this->actingAs($owner)
            ->get(route('petcare.tickets.show', $ticket))
            ->assertOk()
            ->assertSee($body);
        $this->actingAs($recipient)
            ->get(route('petcare.tickets.show', $ticket))
            ->assertOk()
            ->assertSee($body);

        foreach ([$owner, $recipient] as $expectedRecipient) {
            Notification::assertSentTo(
                $expectedRecipient,
                TicketUpdatedNotification::class,
                function (TicketUpdatedNotification $notification) use ($expectedRecipient, $ticket, $body): bool {
                    $metadata = $notification->toArray($expectedRecipient);
                    $mail = $this->renderedNotificationMail($notification, $expectedRecipient);

                    return $metadata['service'] === 'pet-care-clinic'
                        && $metadata['url'] === route('petcare.tickets.show', $ticket)
                        && ! str_contains(
                            json_encode($metadata, JSON_THROW_ON_ERROR),
                            $body,
                        )
                        && ! str_contains($mail, $body);
                },
            );
        }
        Notification::assertNotSentTo($actor, TicketUpdatedNotification::class);
        Notification::assertNotSentTo($suspended, TicketUpdatedNotification::class);
        Notification::assertNotSentTo($permissionOnly, TicketUpdatedNotification::class);
        $audit = AuditLog::query()
            ->where('event', 'petcare.ticket.updated')
            ->where('auditable_id', $ticket->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertStringNotContainsString(
            $body,
            json_encode($audit->context, JSON_THROW_ON_ERROR),
        );
    }

    public function test_petcare_notifications_exclude_eligible_staff_with_only_apes_ticket_access(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $petcareRecipient = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $apesOnlyStaff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $ticket = $this->ticketFor($owner, 'Pet Care cross-instance recipients');
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'pet-care-clinic.tickets.view-all',
        );

        $this->actingAs($actor)
            ->put(route('petcare.tickets.update', $ticket), [
                'message' => 'Pet Care-only notification.',
            ])
            ->assertRedirect(route('petcare.tickets.show', $ticket));

        Notification::assertSentTo(
            $petcareRecipient,
            TicketUpdatedNotification::class,
        );
        Notification::assertNotSentTo($apesOnlyStaff, TicketUpdatedNotification::class);
    }

    public function test_petcare_ticket_messages_keep_internal_bodies_private_from_notifications_and_audits(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $ticket = $this->ticketFor($owner, 'Pet Care privacy ticket');

        $this->actingAs($staff)
            ->put(route('petcare.tickets.update', $ticket), [
                'message' => $body = 'Internal body that must never leave the staff view.',
                'visibility' => 'internal',
            ])
            ->assertRedirect(route('petcare.tickets.show', $ticket));

        $message = $ticket->messages()
            ->where('message', $body)
            ->firstOrFail();
        $this->assertSame($staff->id, $message->user_id);
        $this->assertTrue($message->is_staff_note);
        $this->actingAs($staff)
            ->get(route('petcare.tickets.show', $ticket))
            ->assertOk()
            ->assertSee($body);

        $this->actingAs($owner)
            ->get(route('petcare.tickets.show', $ticket))
            ->assertOk()
            ->assertDontSee($body);
        Notification::assertNothingSent();

        $audit = AuditLog::query()
            ->where('event', 'petcare.ticket.updated')
            ->where('auditable_id', $ticket->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertStringNotContainsString(
            $body,
            json_encode($audit->context, JSON_THROW_ON_ERROR),
        );
    }

    public function test_exact_petcare_staff_can_choose_internal_visibility_and_only_eligible_instance_staff_receive_it(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $recipient = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $ticket = $this->ticketFor($owner, 'Rendered internal privacy Ticket');
        $body = 'A private Pet Care staff message.';

        $this->actingAs($actor)
            ->get(route('petcare.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('for="visibility"', false)
            ->assertSee('Internal staff only');

        $this->put(route('petcare.tickets.update', $ticket), [
            'message' => $body,
            'visibility' => 'internal',
        ])->assertRedirect(route('petcare.tickets.show', $ticket));

        $message = $ticket->messages()
            ->where('message', $body)
            ->firstOrFail();
        $this->assertSame($actor->id, $message->user_id);
        $this->assertTrue($message->is_staff_note);
        $this->actingAs($recipient)
            ->get(route('petcare.tickets.show', $ticket))
            ->assertOk()
            ->assertSee($body);

        $this->actingAs($owner)
            ->get(route('petcare.tickets.show', $ticket))
            ->assertDontSee($body);
        Notification::assertNotSentTo($owner, TicketUpdatedNotification::class);
        Notification::assertNotSentTo($actor, TicketUpdatedNotification::class);
        Notification::assertSentTo(
            $recipient,
            TicketUpdatedNotification::class,
            function (TicketUpdatedNotification $notification) use ($recipient, $ticket, $body): bool {
                $metadata = $notification->toArray($recipient);
                $mail = $this->renderedNotificationMail($notification, $recipient);

                return $metadata['service'] === 'pet-care-clinic'
                    && $metadata['url'] === route('petcare.tickets.show', $ticket)
                    && ! str_contains(
                        json_encode($metadata, JSON_THROW_ON_ERROR),
                        $body,
                    )
                    && ! str_contains($mail, $body);
            },
        );
        $audit = AuditLog::query()
            ->where('event', 'petcare.ticket.updated')
            ->where('auditable_id', $ticket->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertStringNotContainsString(
            $body,
            json_encode($audit->context, JSON_THROW_ON_ERROR),
        );
    }

    public function test_petcare_ticket_index_uses_its_service_specific_presentation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('petcare.tickets.index'))
            ->assertOk()
            ->assertSee('service-label apes-petcare', false)
            ->assertSee('APES Pet Care Clinic Tickets')
            ->assertSee('Support for appointments, consultations, prescriptions, billing and follow-up.');
    }

    public function test_disabled_petcare_ticket_module_is_unavailable_before_ticket_authorization(): void
    {
        $owner = User::factory()->create();
        ModuleInstallation::query()
            ->where('sub_core_key', 'pet-care-clinic')
            ->where('module_key', 'tickets')
            ->update(['enabled' => false, 'disabled_at' => now()]);

        $this->actingAs($owner)
            ->get(route('petcare.tickets.index'))
            ->assertNotFound();
    }

    private function ticketFor(User $owner, string $subject): SupportTicket
    {
        return SupportTicket::create([
            'sub_core_key' => 'pet-care-clinic',
            'user_id' => $owner->id,
            'service_area' => 'appointment',
            'subject' => $subject,
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Pet Care Ticket visibility fixture.',
        ]);
    }

    private function renderedForm(string $html, string $id): string
    {
        $matched = preg_match(
            '/<form id="'.preg_quote($id, '/').'"[^>]*>.*?<\/form>/s',
            $html,
            $matches,
        );
        $this->assertSame(1, $matched, "Expected the rendered {$id} form.");

        return $matches[0];
    }

    private function renderedNotificationMail(
        TicketUpdatedNotification $notification,
        User $recipient,
    ): string {
        $rendered = $notification->toMail($recipient)->render();

        return is_object($rendered) && method_exists($rendered, 'toHtml')
            ? $rendered->toHtml()
            : (string) $rendered;
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

    private function grantRolePermission(string $roleName, string $permissionName): void
    {
        $role = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $roleName)
            ->firstOrFail();
        $permission = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', $permissionName)
            ->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
