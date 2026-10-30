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
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ShelterTicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_shelter_ticket_routes_validate_the_shelter_service_areas_and_never_expose_deletion(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $recipient = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($owner)
            ->get(route('shelter.tickets.index'))
            ->assertOk()
            ->assertSeeInOrder([
                '<option value="adoption" >Adoption</option>',
                '<option value="surrender" >Surrender</option>',
                '<option value="rescue" >Rescue</option>',
                '<option value="fostering" >Fostering</option>',
                '<option value="animal_welfare" >Animal Welfare</option>',
                '<option value="other" >Other</option>',
            ], false);

        $this->post(route('shelter.tickets.store'), [
            'service_area' => 'operations',
            'subject' => 'Invalid Shelter area',
            'priority' => 'medium',
            'description' => 'This must be rejected for Shelter Tickets.',
        ])->assertSessionHasErrors('service_area');

        $this->post(route('shelter.tickets.store'), [
            'service_area' => 'rescue',
            'subject' => 'Shelter rescue intake',
            'priority' => 'high',
            'description' => 'A valid Shelter Ticket.',
        ])->assertRedirect();

        $ticket = SupportTicket::query()
            ->where('sub_core_key', 'shelter-rescue')
            ->firstOrFail();
        $this->assertSame('rescue', $ticket->service_area);
        $this->assertSame('open', $ticket->status);
        Notification::assertSentTo(
            $recipient,
            TicketUpdatedNotification::class,
            function (TicketUpdatedNotification $notification) use ($recipient, $ticket): bool {
                $data = $notification->toArray($recipient);

                $this->assertSame('created', $data['event']);
                $this->assertSame('shelter-rescue', $data['service']);
                $this->assertSame('open', $data['status']);
                $this->assertSame(route('shelter.tickets.show', $ticket), $data['url']);

                return true;
            },
        );
        $this->assertFalse(app('router')->getRoutes()->hasNamedRoute(
            'shelter.tickets.destroy',
        ));
        $this->get(route('shelter.tickets.show', $ticket))
            ->assertDontSee('Delete ticket');
    }

    public function test_shelter_ticket_routes_return_404_for_apes_cic_records_before_authorization(): void
    {
        $owner = User::factory()->create();
        $ticket = SupportTicket::create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'service_area' => 'operations',
            'subject' => 'APES-only ticket',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Not a Shelter record.',
        ]);

        $this->actingAs($owner)
            ->get(route('shelter.tickets.show', $ticket))
            ->assertNotFound();
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
            'shelter-rescue.tickets.view-own',
        );
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'shelter-rescue.tickets.view-own',
        );
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'shelter-rescue.tickets.view-all',
        );

        $this->actingAs($owner->fresh());
        $this->assertSame([], SupportTicket::query()
            ->forSubCore('shelter-rescue')
            ->visibleTo($owner->fresh(), 'shelter-rescue')
            ->pluck('id')
            ->all());
        $this->actingAs($staff->fresh());
        $this->assertSame([], SupportTicket::query()
            ->forSubCore('shelter-rescue')
            ->visibleTo($staff->fresh(), 'shelter-rescue')
            ->pluck('id')
            ->all());
        $this->assertNotSame($ownerTicket->id, $staffTicket->id);
    }

    public function test_shelter_owner_and_exact_staff_visibility_are_isolated_by_instance(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $owned = $this->ticketFor($owner, 'Owned Shelter Ticket');
        $other = $this->ticketFor($otherOwner, 'Other Shelter Ticket');

        $this->actingAs($owner)
            ->get(route('shelter.tickets.index'))
            ->assertOk()
            ->assertSee($owned->subject)
            ->assertDontSee($other->subject);
        $this->get(route('shelter.tickets.show', $other))->assertForbidden();

        $this->actingAs($staff)
            ->get(route('shelter.tickets.index'))
            ->assertOk()
            ->assertSee($owned->subject)
            ->assertSee($other->subject);
    }

    public function test_apes_and_shelter_ticket_view_permissions_do_not_cross_grant_access(): void
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
        $this->ticketFor($owner, 'Shelter permission boundary');

        $this->removeRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'shelter-rescue.tickets.view-own',
        );
        $this->actingAs($owner->fresh())
            ->get(route('apes-cic.tickets.index'))
            ->assertOk();
        $this->get(route('shelter.tickets.index'))->assertForbidden();

        $this->grantRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'shelter-rescue.tickets.view-own',
        );
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'apes-cic.tickets.view-own',
        );
        $this->actingAs($owner->fresh())
            ->get(route('shelter.tickets.index'))
            ->assertOk();
        $this->get(route('apes-cic.tickets.index'))->assertForbidden();
    }

    public function test_shelter_cross_sub_core_ticket_update_is_a_non_disclosing_404(): void
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
            ->put(route('shelter.tickets.update', $apesTicket), [
                'message' => 'Must never be written.',
            ])
            ->assertNotFound();
        $this->assertSame('open', $apesTicket->fresh()->status);
        $this->assertSame(0, $apesTicket->messages()->count());
    }

    public function test_shelter_assignment_requires_an_eligible_actor_with_assign_and_an_exact_view_all_candidate(): void
    {
        $owner = User::factory()->create();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $candidate = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $ticket = $this->ticketFor($owner, 'Shelter assignment Ticket');

        $this->actingAs($actor)
            ->put(route('shelter.tickets.update', $ticket), [
                'assigned_to' => $candidate->id,
            ])
            ->assertRedirect(route('shelter.tickets.show', $ticket));
        $this->assertSame($candidate->id, $ticket->fresh()->assigned_to);

        $adminActor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'shelter-rescue.tickets.view-all',
        );
        $this->actingAs($adminActor)
            ->put(route('shelter.tickets.update', $ticket), [
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
        $shelterAssignee = User::factory()
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
        $shelterTicket = $this->ticketFor($owner, 'Shelter assignment intent');

        foreach ([
            [$apesTicket, $apesAssignee, 'apes-cic.tickets'],
            [$shelterTicket, $shelterAssignee, 'shelter.tickets'],
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
            'shelter-rescue.tickets.view-all',
        );

        foreach ([
            [$apesTicket, $apesAssignee, 'apes-cic.tickets'],
            [$shelterTicket, $shelterAssignee, 'shelter.tickets'],
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
                'priority' => 'medium',
            ])->assertRedirect(route($routePrefix.'.show', $ticket));
            $this->assertSame($assignee->id, $ticket->fresh()->assigned_to);

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

    public function test_shelter_terminal_timestamp_and_no_op_semantics_match_apes_tickets(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $ticket = $this->ticketFor($owner, 'Shelter terminal Ticket');

        $this->actingAs($staff)
            ->put(route('shelter.tickets.update', $ticket), [
                'status' => 'resolved',
                'priority' => 'medium',
            ])
            ->assertRedirect(route('shelter.tickets.show', $ticket));
        $closedAt = $ticket->fresh()->closed_at;

        $this->put(route('shelter.tickets.update', $ticket), [
            'status' => 'resolved',
            'priority' => 'medium',
        ])->assertSessionHasErrors('ticket');
        $this->assertTrue($ticket->fresh()->closed_at->equalTo($closedAt));
    }

    public function test_shelter_assignment_actor_must_be_eligible_staff_with_exact_assign(): void
    {
        $owner = User::factory()->create();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $candidate = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $ticket = $this->ticketFor($owner, 'Shelter actor authorization Ticket');
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'shelter-rescue.tickets.assign',
        );

        $this->actingAs($actor->fresh())
            ->put(route('shelter.tickets.update', $ticket), [
                'assigned_to' => $candidate->id,
            ])
            ->assertForbidden();
        $this->assertNull($ticket->fresh()->assigned_to);
    }

    public function test_shelter_public_ticket_recipients_are_exact_staff_or_owner_and_exclude_the_actor(): void
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
            'name' => 'shelter-ticket-helper',
            'guard_name' => 'web',
            'is_protected' => false,
        ]);
        $permissionOnlyRole->permissions()->attach(Permission::query()
            ->where('name', 'shelter-rescue.tickets.view-all')
            ->value('id'));
        app(AuthorizationRoleMaterializer::class)->grant(
            $permissionOnly,
            $permissionOnlyRole,
            RoleSource::SOURCE_LOCAL,
            actor: $actor,
        );
        $ticket = $this->ticketFor($owner, 'Shelter recipient filtering Ticket');
        $body = 'Public update body remains private metadata.';

        $this->actingAs($actor)
            ->put(route('shelter.tickets.update', $ticket), [
                'message' => $body,
                'visibility' => 'public',
            ])
            ->assertRedirect(route('shelter.tickets.show', $ticket));

        foreach ([$owner, $recipient] as $expectedRecipient) {
            Notification::assertSentTo(
                $expectedRecipient,
                TicketUpdatedNotification::class,
                function (TicketUpdatedNotification $notification) use ($expectedRecipient, $ticket, $body): bool {
                    $metadata = $notification->toArray($expectedRecipient);

                    return $metadata['service'] === 'shelter-rescue'
                        && $metadata['url'] === route('shelter.tickets.show', $ticket)
                        && ! str_contains(
                            json_encode($metadata, JSON_THROW_ON_ERROR),
                            $body,
                        );
                },
            );
        }
        Notification::assertNotSentTo($actor, TicketUpdatedNotification::class);
        Notification::assertNotSentTo($suspended, TicketUpdatedNotification::class);
        Notification::assertNotSentTo($permissionOnly, TicketUpdatedNotification::class);
    }

    public function test_shelter_notifications_exclude_eligible_staff_with_only_apes_ticket_access(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $shelterRecipient = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $apesOnlyStaff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $ticket = $this->ticketFor($owner, 'Shelter cross-instance recipients');
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'shelter-rescue.tickets.view-all',
        );

        $this->actingAs($actor)
            ->put(route('shelter.tickets.update', $ticket), [
                'message' => 'Shelter-only notification.',
            ])
            ->assertRedirect(route('shelter.tickets.show', $ticket));

        Notification::assertSentTo(
            $shelterRecipient,
            TicketUpdatedNotification::class,
        );
        Notification::assertNotSentTo($apesOnlyStaff, TicketUpdatedNotification::class);
    }

    public function test_shelter_ticket_messages_keep_internal_bodies_private_from_notifications_and_audits(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $ticket = $this->ticketFor($owner, 'Shelter privacy ticket');

        $this->actingAs($staff)
            ->put(route('shelter.tickets.update', $ticket), [
                'message' => 'Internal body that must never leave the staff view.',
                'visibility' => 'internal',
            ])
            ->assertRedirect(route('shelter.tickets.show', $ticket));

        $this->actingAs($owner)
            ->get(route('shelter.tickets.show', $ticket))
            ->assertOk()
            ->assertDontSee('Internal body that must never leave the staff view.');
        Notification::assertNothingSent();

        $audit = AuditLog::query()
            ->where('event', 'shelter.ticket.updated')
            ->where('auditable_id', $ticket->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertStringNotContainsString(
            'Internal body that must never leave the staff view.',
            json_encode($audit->context, JSON_THROW_ON_ERROR),
        );
    }

    public function test_exact_shelter_staff_can_choose_internal_visibility_and_only_eligible_instance_staff_receive_it(): void
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
        $body = 'A private Shelter staff message.';

        $this->actingAs($actor)
            ->get(route('shelter.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('for="visibility"', false)
            ->assertSee('Internal staff only');

        $this->put(route('shelter.tickets.update', $ticket), [
            'message' => $body,
            'visibility' => 'internal',
        ])->assertRedirect(route('shelter.tickets.show', $ticket));

        $this->actingAs($owner)
            ->get(route('shelter.tickets.show', $ticket))
            ->assertDontSee($body);
        Notification::assertNotSentTo($owner, TicketUpdatedNotification::class);
        Notification::assertNotSentTo($actor, TicketUpdatedNotification::class);
        Notification::assertSentTo(
            $recipient,
            TicketUpdatedNotification::class,
            function (TicketUpdatedNotification $notification) use ($recipient, $ticket, $body): bool {
                $metadata = $notification->toArray($recipient);

                return $metadata['service'] === 'shelter-rescue'
                    && $metadata['url'] === route('shelter.tickets.show', $ticket)
                    && ! str_contains(
                        json_encode($metadata, JSON_THROW_ON_ERROR),
                        $body,
                    );
            },
        );
        $audit = AuditLog::query()
            ->where('event', 'shelter.ticket.updated')
            ->where('auditable_id', $ticket->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertStringNotContainsString(
            $body,
            json_encode($audit->context, JSON_THROW_ON_ERROR),
        );
    }

    public function test_shelter_ticket_index_uses_its_service_specific_presentation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('shelter.tickets.index'))
            ->assertOk()
            ->assertSee('service-label apes-shelter', false)
            ->assertSee('APES Shelter and Rescue Tickets')
            ->assertSee('Support for adoption, surrender, rescue, fostering and animal welfare.');
    }

    public function test_disabled_shelter_ticket_module_is_unavailable_before_ticket_authorization(): void
    {
        $owner = User::factory()->create();
        ModuleInstallation::query()
            ->where('sub_core_key', 'shelter-rescue')
            ->where('module_key', 'tickets')
            ->update(['enabled' => false, 'disabled_at' => now()]);

        $this->actingAs($owner)
            ->get(route('shelter.tickets.index'))
            ->assertNotFound();
    }

    private function ticketFor(User $owner, string $subject): SupportTicket
    {
        return SupportTicket::create([
            'sub_core_key' => 'shelter-rescue',
            'user_id' => $owner->id,
            'service_area' => 'rescue',
            'subject' => $subject,
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Shelter Ticket visibility fixture.',
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
