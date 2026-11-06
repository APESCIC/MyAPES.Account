<?php

namespace Tests\Feature;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\DirectoryGroup;
use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\AuthorizationRoleMaterializer;
use App\Services\DirectoryRoleSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Cases\Notifications\ShelterCaseUpdatedNotification;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\Consultations\Notifications\ConsultationUpdatedNotification;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Tests\Concerns\CreatesClinicalPersonas;
use Tests\TestCase;

class SecurityRemediationTest extends TestCase
{
    use CreatesClinicalPersonas;
    use RefreshDatabase;

    public function test_owner_comment_only_updates_preserve_assignments_and_ticket_state(): void
    {
        Notification::fake();
        $owner = $this->user(User::ROLE_SERVICE_USER);
        $staff = $this->user(User::ROLE_STAFF);
        [$ticket, $case, $consultation] = $this->records(
            $owner,
            $staff,
        );

        $this->actingAs($owner)->put(
            route('apes-cic.tickets.update', $ticket),
            ['message' => 'Customer follow-up'],
        )->assertRedirect();
        $this->actingAs($owner)->put(
            route('shelter.cases.update', $case),
            ['status' => 'in_review', 'details' => 'Owner update'],
        )->assertRedirect();
        $this->actingAs($owner)->put(
            route('petcare.consultations.update', $consultation),
            ['status' => 'in_progress', 'notes' => 'Owner update'],
        )->assertForbidden();

        $ticket->refresh();
        $this->assertSame($staff->id, $ticket->assigned_to);
        $this->assertSame('open', $ticket->status);
        $this->assertSame('medium', $ticket->priority);
        $this->assertNull($ticket->closed_at);
        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'user_id' => $owner->id,
            'message' => 'Customer follow-up',
            'is_staff_note' => false,
        ]);
        $this->assertSame($staff->id, $case->fresh()->assigned_to);
        $this->assertSame(
            $staff->id,
            $consultation->fresh()->assigned_to,
        );
    }

    public function test_owner_cannot_submit_ticket_status_or_priority_fields(): void
    {
        Notification::fake();
        $owner = $this->user(User::ROLE_SERVICE_USER);
        $staff = $this->user(User::ROLE_STAFF);
        [$ticket] = $this->records($owner, $staff);

        foreach ([
            ['status' => 'closed'],
            ['priority' => 'urgent'],
            ['status' => 'resolved', 'priority' => 'high'],
        ] as $forbiddenFields) {
            $response = $this->actingAs($owner)->put(
                route('apes-cic.tickets.update', $ticket),
                [...$forbiddenFields, 'message' => 'Forbidden mixed update'],
            );
            $this->assertSame(403, $response->getStatusCode());
        }

        $ticket->refresh();
        $this->assertSame('open', $ticket->status);
        $this->assertSame('medium', $ticket->priority);
        $this->assertSame($staff->id, $ticket->assigned_to);
        $this->assertNull($ticket->closed_at);
        $this->assertDatabaseMissing('support_ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'message' => 'Forbidden mixed update',
        ]);
    }

    public function test_ticket_form_exposes_only_actions_the_actor_can_perform(): void
    {
        $owner = $this->user(User::ROLE_SERVICE_USER);
        $staff = $this->user(User::ROLE_STAFF);
        [$ticket] = $this->records($owner, $staff);

        $ownerResponse = $this->actingAs($owner)->get(
            route('apes-cic.tickets.show', $ticket),
        );
        $ownerResponse->assertOk();
        $ownerResponse->assertSee('name="message"', false);
        $ownerResponse->assertDontSee('name="status"', false);
        $ownerResponse->assertDontSee('name="priority"', false);
        $ownerResponse->assertDontSee('name="assigned_to"', false);

        $staffResponse = $this->actingAs($staff)->get(
            route('apes-cic.tickets.show', $ticket),
        );
        $staffResponse->assertOk();
        $staffResponse->assertSee('name="message"', false);
        $staffResponse->assertSee('name="status"', false);
        $staffResponse->assertSee('name="priority"', false);
        $staffResponse->assertSee('name="assigned_to"', false);
    }

    public function test_ticket_update_requires_the_namespaced_update_permission(): void
    {
        Notification::fake();
        $owner = $this->user(User::ROLE_SERVICE_USER);
        $staff = $this->user(User::ROLE_STAFF);
        [$ticket] = $this->records($owner, $staff);
        $this->removeStaffPermission('apes-cic.tickets.update-all');

        $response = $this->actingAs($staff)->put(
            route('apes-cic.tickets.update', $ticket),
            ['status' => 'in_progress', 'priority' => 'high'],
        );
        $this->assertSame(403, $response->getStatusCode());

        $ticket->refresh();
        $this->assertSame('open', $ticket->status);
        $this->assertSame('medium', $ticket->priority);
    }

    public function test_ticket_comment_requires_the_namespaced_comment_permission(): void
    {
        Notification::fake();
        $owner = $this->user(User::ROLE_SERVICE_USER);
        $staff = $this->user(User::ROLE_STAFF);
        [$ticket] = $this->records($owner, $staff);
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'apes-cic.tickets.comment-own',
        );

        $response = $this->actingAs($owner)->put(
            route('apes-cic.tickets.update', $ticket),
            ['message' => 'Unauthorized owner comment'],
        );
        $this->assertSame(403, $response->getStatusCode());
        $this->assertDatabaseMissing('support_ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'message' => 'Unauthorized owner comment',
        ]);
    }

    public function test_ticket_mixed_update_requires_every_requested_permission(): void
    {
        Notification::fake();
        $owner = $this->user(User::ROLE_SERVICE_USER);
        $staff = $this->user(User::ROLE_STAFF);
        [$ticket] = $this->records($owner, $staff);
        $this->removeStaffPermission('apes-cic.tickets.comment-own');

        $response = $this->actingAs($staff)->put(
            route('apes-cic.tickets.update', $ticket),
            [
                'status' => 'in_progress',
                'priority' => 'high',
                'message' => 'Unauthorized mixed comment',
            ],
        );
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertDatabaseMissing('support_ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'message' => 'Unauthorized mixed comment',
        ]);
    }

    public function test_ticket_close_requires_the_namespaced_close_permission(): void
    {
        Notification::fake();
        $owner = $this->user(User::ROLE_SERVICE_USER);
        $staff = $this->user(User::ROLE_STAFF);
        [$ticket] = $this->records($owner, $staff);
        $this->removeStaffPermission('apes-cic.tickets.close');

        $response = $this->actingAs($staff)->put(
            route('apes-cic.tickets.update', $ticket),
            ['status' => 'closed', 'priority' => 'medium'],
        );
        $this->assertSame(403, $response->getStatusCode());

        $ticket->refresh();
        $this->assertSame('open', $ticket->status);
        $this->assertNull($ticket->closed_at);

        $ticket->forceFill([
            'status' => 'resolved',
            'closed_at' => now(),
        ])->save();
        $response = $this->actingAs($staff)->put(
            route('apes-cic.tickets.update', $ticket),
            ['status' => 'closed', 'priority' => 'medium'],
        );
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('resolved', $ticket->fresh()->status);
    }

    public function test_ticket_assignment_requires_the_namespaced_assign_permission(): void
    {
        Notification::fake();
        $owner = $this->user(User::ROLE_SERVICE_USER);
        $staff = $this->user(User::ROLE_STAFF);
        $otherStaff = $this->user(User::ROLE_STAFF);
        [$ticket] = $this->records($owner, $staff);
        $this->removeStaffPermission('apes-cic.tickets.assign');

        $response = $this->actingAs($staff)->put(
            route('apes-cic.tickets.update', $ticket),
            ['assigned_to' => $otherStaff->id],
        );
        $this->assertSame(403, $response->getStatusCode());

        $this->assertSame($staff->id, $ticket->fresh()->assigned_to);
    }

    public function test_owner_explicit_assignment_probes_have_one_forbidden_response_and_sanitized_audit(): void
    {
        Notification::fake();
        $owner = $this->user(User::ROLE_SERVICE_USER);
        $staff = $this->user(User::ROLE_STAFF);
        $replacement = $this->user(User::ROLE_STAFF);
        [$ticket, $case, $consultation] = $this->records($owner, $staff);
        $workflows = [
            [
                'route' => route('apes-cic.tickets.update', $ticket),
                'payload' => [
                    'status' => 'open',
                    'priority' => 'medium',
                    'message' => null,
                ],
                'record' => $ticket,
            ],
            [
                'route' => route('shelter.cases.update', $case),
                'payload' => [
                    'status' => 'in_review',
                    'details' => 'Owner update',
                ],
                'record' => $case,
            ],
            [
                'route' => route(
                    'petcare.consultations.update',
                    $consultation,
                ),
                'payload' => [
                    'status' => 'in_progress',
                    'notes' => 'Owner update',
                ],
                'record' => $consultation,
            ],
        ];

        foreach ($workflows as $workflow) {
            $existing = $this->actingAs($owner)->put(
                $workflow['route'],
                [...$workflow['payload'], 'assigned_to' => $replacement->id],
            );
            $missing = $this->actingAs($owner)->put(
                $workflow['route'],
                [...$workflow['payload'], 'assigned_to' => 999999],
            );
            $unassign = $this->actingAs($owner)->put(
                $workflow['route'],
                [...$workflow['payload'], 'assigned_to' => null],
            );

            foreach ([$existing, $missing, $unassign] as $response) {
                $response->assertForbidden();
            }
            $this->assertSameIgnoringCspNonce(
                $existing->getContent(),
                $missing->getContent(),
            );
            $this->assertSameIgnoringCspNonce(
                $existing->getContent(),
                $unassign->getContent(),
            );
            $this->assertSame(
                $staff->id,
                $workflow['record']->fresh()->assigned_to,
            );
        }

        $audits = AuditLog::query()
            ->where('event', 'authorization.assignment_denied')
            ->get();
        $this->assertCount(9, $audits);
        foreach ($audits as $audit) {
            $contextKeys = array_keys($audit->context);
            sort($contextKeys);
            $this->assertSame(
                ['actor_id', 'method', 'reason_code', 'route_name'],
                $contextKeys,
            );
        }
        $this->assertStringNotContainsString(
            (string) $staff->id,
            $audits->pluck('context')->toJson(),
        );
    }

    public function test_staff_assignment_validation_hides_why_an_assignee_is_unavailable(): void
    {
        Notification::fake();
        $owner = $this->user(User::ROLE_SERVICE_USER);
        $actor = $this->grantClinicalRole($this->user(User::ROLE_STAFF));
        $ordinary = $this->user(User::ROLE_SERVICE_USER);
        $suspended = $this->user(User::ROLE_STAFF, [
            'suspended_at' => now(),
            'suspension_reason' => 'Security hold',
        ]);
        $permissionOnly = $this->permissionOnlyUser();
        [$ticket, $case, $consultation] = $this->records($owner, $actor);
        $workflows = [
            [
                'route' => route('apes-cic.tickets.update', $ticket),
                'payload' => [
                    'status' => 'open',
                    'priority' => 'medium',
                    'message' => null,
                ],
                'record' => $ticket,
            ],
            [
                'route' => route('shelter.cases.update', $case),
                'payload' => [
                    'status' => 'in_review',
                    'details' => 'Staff update',
                ],
                'record' => $case,
            ],
            [
                'route' => route(
                    'petcare.consultations.update',
                    $consultation,
                ),
                'payload' => [
                    'status' => 'in_progress',
                    'notes' => 'Staff update',
                ],
                'record' => $consultation,
            ],
        ];

        foreach ($workflows as $workflow) {
            foreach ([
                999999,
                $ordinary->id,
                $suspended->id,
                $permissionOnly->id,
            ] as $candidate) {
                $this->actingAs($actor)
                    ->put($workflow['route'], [
                        ...$workflow['payload'],
                        'assigned_to' => $candidate,
                    ])
                    ->assertRedirect()
                    ->assertSessionHasErrors([
                        'assigned_to' => 'The selected assignee is unavailable.',
                    ]);
            }
        }

        $replacement = $this->user(User::ROLE_STAFF);
        foreach ($workflows as $workflow) {
            $this->actingAs($actor)->put(
                $workflow['route'],
                [
                    ...$workflow['payload'],
                    'assigned_to' => $replacement->id,
                ],
            )->assertRedirect();
            $this->assertSame(
                $replacement->id,
                $workflow['record']->fresh()->assigned_to,
            );

            $this->actingAs($actor)->put(
                $workflow['route'],
                [...$workflow['payload'], 'assigned_to' => null],
            )->assertRedirect();
            $this->assertNull(
                $workflow['record']->fresh()->assigned_to,
            );
        }
    }

    public function test_all_notification_paths_exclude_permission_only_or_suspended_recipients(): void
    {
        Notification::fake();
        $owner = $this->user(User::ROLE_SERVICE_USER);
        $actor = $this->grantClinicalRole($this->user(User::ROLE_STAFF));
        $eligibleRecipient = $this->user(User::ROLE_STAFF);
        $permissionOnly = $this->permissionOnlyUser();
        $suspended = $this->user(User::ROLE_STAFF, [
            'suspended_at' => now(),
            'suspension_reason' => 'Security hold',
        ]);
        [$ticket, $case, $consultation] = $this->records(
            $owner,
            $permissionOnly,
        );

        $this->actingAs($actor)->put(
            route('apes-cic.tickets.update', $ticket),
            ['status' => 'open', 'priority' => 'high', 'message' => null],
        )->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($actor)->put(
            route('shelter.cases.update', $case),
            ['status' => 'in_review', 'details' => 'Staff update'],
        )->assertRedirect();
        $this->actingAs($actor)->put(
            route('petcare.consultations.update', $consultation),
            ['status' => 'in_progress', 'notes' => 'Staff update'],
        )->assertRedirect();

        Notification::assertNotSentTo(
            $permissionOnly,
            TicketUpdatedNotification::class,
        );
        Notification::assertNotSentTo(
            $permissionOnly,
            ShelterCaseUpdatedNotification::class,
        );
        Notification::assertNotSentTo(
            $permissionOnly,
            ConsultationUpdatedNotification::class,
        );
        Notification::assertNothingSentTo($suspended);
        Notification::assertSentTo(
            $eligibleRecipient,
            TicketUpdatedNotification::class,
        );
        Notification::assertSentTo(
            $eligibleRecipient,
            ShelterCaseUpdatedNotification::class,
        );
        Notification::assertSentTo(
            $eligibleRecipient,
            ConsultationUpdatedNotification::class,
        );
        Notification::assertSentTo($owner, TicketUpdatedNotification::class);
        Notification::assertSentTo(
            $owner,
            ShelterCaseUpdatedNotification::class,
        );
        Notification::assertSentTo(
            $owner,
            ConsultationUpdatedNotification::class,
        );
    }

    public function test_permission_only_owner_cannot_see_or_change_assignment_controls_or_staff_identities(): void
    {
        $owner = $this->permissionOnlyUser();
        $staff = $this->user(User::ROLE_STAFF, [
            'name' => 'Hidden eligible staff identity',
        ]);
        $replacement = $this->user(User::ROLE_STAFF);
        [$ticket, $case, $consultation] = $this->records($owner, $staff);

        foreach ([
            [
                'show' => route('apes-cic.tickets.show', $ticket),
                'update' => route('apes-cic.tickets.update', $ticket),
                'payload' => [
                    'status' => 'open',
                    'priority' => 'medium',
                    'message' => null,
                ],
                'record' => $ticket,
            ],
            [
                'show' => route('shelter.cases.show', $case),
                'update' => route('shelter.cases.update', $case),
                'payload' => [
                    'status' => 'in_review',
                    'details' => 'Owner update',
                ],
                'record' => $case,
            ],
            [
                'show' => route(
                    'petcare.consultations.show',
                    $consultation,
                ),
                'update' => route(
                    'petcare.consultations.update',
                    $consultation,
                ),
                'payload' => [
                    'status' => 'in_progress',
                    'notes' => 'Owner update',
                ],
                'record' => $consultation,
            ],
        ] as $workflow) {
            $this->actingAs($owner)
                ->get($workflow['show'])
                ->assertOk()
                ->assertDontSee('name="assigned_to"', false)
                ->assertDontSee($staff->name);
            $this->actingAs($owner)
                ->put($workflow['update'], [
                    ...$workflow['payload'],
                    'assigned_to' => $replacement->id,
                ])
                ->assertForbidden();
            $this->assertSame(
                $staff->id,
                $workflow['record']->fresh()->assigned_to,
            );
        }
    }

    public function test_directory_revocation_with_a_retained_custom_permission_removes_assignment_eligibility(): void
    {
        Notification::fake();
        $revoked = $this->permissionOnlyUser();
        $directoryGroup = DirectoryGroup::query()
            ->where('name', 'myapesaccount.staff')
            ->firstOrFail();
        $directoryGroup->forceFill([
            'member_count' => 1,
            'status' => DirectoryGroup::STATUS_PRESENT,
        ])->save();
        $staffRole = Role::query()
            ->where('guard_name', 'web')
            ->where('name', AuthorizationProfile::ROLE_STAFF)
            ->firstOrFail();
        app(AuthorizationRoleMaterializer::class)->grant(
            $revoked,
            $staffRole,
            RoleSource::SOURCE_DIRECTORY,
            $directoryGroup,
        );

        app(DirectoryRoleSynchronizer::class)->revoke($revoked);
        $revoked->refresh();

        $this->assertTrue(
            $revoked->roles()
                ->where('roles.is_protected', false)
                ->whereHas(
                    'permissions',
                    static fn ($permissionQuery) => $permissionQuery
                        ->where(
                            'permissions.name',
                            AuthorizationProfile::PERMISSION_STAFF_ACCESS,
                        ),
                )
                ->exists(),
        );
        $this->assertFalse(
            User::query()
                ->eligibleStaff()
                ->whereKey($revoked->id)
                ->exists(),
        );

        $owner = $this->user(User::ROLE_SERVICE_USER);
        $actor = $this->grantClinicalRole($this->user(User::ROLE_STAFF));
        $assignee = $this->user(User::ROLE_STAFF, [
            'name' => 'Directory-eligible assignment identity',
        ]);
        [$ticket, $case, $consultation] = $this->records(
            $owner,
            $assignee,
        );
        $workflows = [
            [
                'show' => route('apes-cic.tickets.show', $ticket),
                'update' => route('apes-cic.tickets.update', $ticket),
                'denied_status' => 403,
                'payload' => [
                    'status' => 'open',
                    'priority' => 'high',
                    'message' => null,
                ],
                'record' => $ticket,
            ],
            [
                'show' => route('shelter.cases.show', $case),
                'update' => route('shelter.cases.update', $case),
                'denied_status' => 404,
                'payload' => [
                    'status' => 'in_review',
                    'details' => 'Staff update',
                ],
                'record' => $case,
            ],
            [
                'show' => route(
                    'petcare.consultations.show',
                    $consultation,
                ),
                'update' => route(
                    'petcare.consultations.update',
                    $consultation,
                ),
                'denied_status' => 403,
                'payload' => [
                    'status' => 'in_progress',
                    'notes' => 'Staff update',
                ],
                'record' => $consultation,
            ],
        ];

        foreach ($workflows as $workflow) {
            $this->actingAs($revoked)
                ->get($workflow['show'])
                ->assertStatus($workflow['denied_status'])
                ->assertDontSee($assignee->name);
            $this->actingAs($revoked)
                ->put($workflow['update'], [
                    ...$workflow['payload'],
                    'assigned_to' => $assignee->id,
                ])
                ->assertStatus($workflow['denied_status']);
            $this->assertSame(
                $assignee->id,
                $workflow['record']->fresh()->assigned_to,
            );

            $this->actingAs($actor)
                ->put($workflow['update'], $workflow['payload'])
                ->assertRedirect();
        }

        Notification::assertNotSentTo(
            $revoked,
            TicketUpdatedNotification::class,
        );
        Notification::assertNotSentTo(
            $revoked,
            ShelterCaseUpdatedNotification::class,
        );
        Notification::assertNotSentTo(
            $revoked,
            ConsultationUpdatedNotification::class,
        );
        Notification::assertSentTo(
            $assignee,
            TicketUpdatedNotification::class,
        );
        Notification::assertSentTo(
            $assignee,
            ShelterCaseUpdatedNotification::class,
        );
        Notification::assertSentTo(
            $assignee,
            ConsultationUpdatedNotification::class,
        );
    }

    /**
     * @return array{SupportTicket, ShelterCase, PetCareConsultation}
     */
    private function records(User $owner, User $assignee): array
    {
        $shelterPet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_SHELTER,
            'name' => 'Shelter animal',
            'species' => 'dog',
        ]);
        $carePet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Care animal',
            'species' => 'cat',
        ]);

        return [
            SupportTicket::query()->create([
                'user_id' => $owner->id,
                'assigned_to' => $assignee->id,
                'service_area' => 'it',
                'subject' => 'Security test ticket',
                'priority' => 'medium',
                'status' => 'open',
                'description' => 'Assignment integrity.',
            ]),
            ShelterCase::query()->create([
                'pet_profile_id' => $shelterPet->id,
                'user_id' => $owner->id,
                'assigned_to' => $assignee->id,
                'case_type' => 'rescue',
                'status' => 'open',
                'title' => 'Security test case',
                'details' => 'Assignment integrity.',
            ]),
            PetCareConsultation::query()->create([
                'pet_profile_id' => $carePet->id,
                'user_id' => $owner->id,
                'assigned_to' => $assignee->id,
                'subject' => 'Security test consultation',
                'status' => 'open',
                'notes' => 'Assignment integrity.',
            ]),
        ];
    }

    private function permissionOnlyUser(): User
    {
        $user = $this->user(User::ROLE_SERVICE_USER);
        $role = Role::query()->create([
            'name' => 'permission-only-'.uniqid(),
            'guard_name' => 'web',
        ]);
        $permission = Permission::query()
            ->where('name', AuthorizationProfile::PERMISSION_STAFF_ACCESS)
            ->where('guard_name', 'web')
            ->firstOrFail();
        $role->permissions()->attach($permission->id);
        app(AuthorizationRoleMaterializer::class)->grant(
            $user,
            $role,
            RoleSource::SOURCE_LOCAL,
            actor: $user,
        );

        return $user->refresh();
    }

    private function removeStaffPermission(string $permissionName): void
    {
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            $permissionName,
        );
    }

    private function removeRolePermission(
        string $roleName,
        string $permissionName,
    ): void {
        $role = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $roleName)
            ->firstOrFail();
        $permission = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', $permissionName)
            ->firstOrFail();

        $role->permissions()->detach($permission->id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(string $accessLevel, array $attributes = []): User
    {
        return User::factory()
            ->accessLevel($accessLevel)
            ->create($attributes)
            ->refresh();
    }
}
