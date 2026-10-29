<?php

namespace Tests\Feature;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Cases\Notifications\ShelterCaseUpdatedNotification;
use Plugins\PetProfiles\Models\PetProfile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ShelterCaseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_shelter_case_visibility_requires_exact_instance_permissions(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $ownedCase = $this->shelterCaseFor($owner, 'Revoked owner case');
        $staffCase = $this->shelterCaseFor($staff, 'Revoked staff case');

        $this->removeRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'shelter-rescue.cases.view-own',
        );
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'shelter-rescue.cases.view-own',
        );
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'shelter-rescue.cases.view-all',
        );

        $owner = $owner->fresh();
        $staff = $staff->fresh();
        $this->actingAs($owner);
        $this->assertSame([], ShelterCase::query()
            ->forSubCore(ShelterCase::SUB_CORE_SHELTER_RESCUE)
            ->visibleTo($owner, ShelterCase::SUB_CORE_SHELTER_RESCUE)
            ->pluck('id')
            ->all());
        $this->actingAs($staff);
        $this->assertSame([], ShelterCase::query()
            ->forSubCore(ShelterCase::SUB_CORE_SHELTER_RESCUE)
            ->visibleTo($staff, ShelterCase::SUB_CORE_SHELTER_RESCUE)
            ->pluck('id')
            ->all());

        $this->actingAs($owner)
            ->get(route('shelter.cases.index'))
            ->assertForbidden();
        $this->get(route('shelter.cases.show', $ownedCase))
            ->assertNotFound();
        $this->actingAs($staff)
            ->get(route('shelter.cases.index'))
            ->assertForbidden();

        $this->assertNotSame($ownedCase->id, $staffCase->id);
    }

    public function test_shelter_case_creation_requires_create_and_an_authorized_shelter_pet(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $recipient = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $ownedPet = $this->shelterPetFor($owner, 'Authorized Shelter pet');
        $otherPet = $this->shelterPetFor($otherOwner, 'Other owner Shelter pet');
        $petCarePet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Pet Care domain pet',
            'species' => 'cat',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'shelter-rescue.cases.create',
        );

        $this->actingAs($owner->fresh())
            ->get(route('shelter.cases.index'))
            ->assertOk()
            ->assertDontSee('action="'.route('shelter.cases.store').'"', false);
        $this->post(route('shelter.cases.store'), [
            'pet_profile_id' => $ownedPet->id,
            'case_type' => 'rescue',
            'title' => 'Forbidden Shelter case',
        ])->assertForbidden();

        $this->grantRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'shelter-rescue.cases.create',
        );
        $this->actingAs($owner->fresh());
        $this->post(route('shelter.cases.store'), [
            'case_type' => 'rescue',
            'title' => 'Missing pet link',
        ])->assertSessionHasErrors('pet_profile_id');
        foreach ([
            999999 => 'Missing pet case',
            $petCarePet->id => 'Cross-domain case',
            $otherPet->id => 'Non-visible owner case',
        ] as $petId => $title) {
            $response = $this->post(route('shelter.cases.store'), [
                'pet_profile_id' => $petId,
                'case_type' => 'rescue',
                'title' => $title,
            ]);
            $this->assertSame(404, $response->getStatusCode());
            $this->assertDatabaseMissing('shelter_cases', ['title' => $title]);
        }

        $response = $this->post(route('shelter.cases.store'), [
            'pet_profile_id' => $ownedPet->id,
            'case_type' => 'adoption',
            'title' => 'Authorized Shelter case',
            'details' => 'Owner and pet linkage must remain intact.',
        ]);
        $case = ShelterCase::query()
            ->where('title', 'Authorized Shelter case')
            ->firstOrFail();
        $response->assertRedirect(route('shelter.cases.show', $case));
        $this->assertSame($ownedPet->id, $case->pet_profile_id);
        $this->assertSame($owner->id, $case->user_id);
        $this->assertSame(ShelterCase::SUB_CORE_SHELTER_RESCUE, $case->sub_core_key);
        $this->assertSame('adoption', $case->case_type);
        $this->assertSame('open', $case->status);
        Notification::assertSentTo(
            $recipient,
            ShelterCaseUpdatedNotification::class,
            static fn (ShelterCaseUpdatedNotification $notification): bool => $notification
                ->toArray($recipient)['status'] === 'open',
        );
        $this->assertSame(
            'open',
            AuditLog::query()
                ->where('event', 'shelter.case.created')
                ->where('auditable_id', $case->id)
                ->firstOrFail()
                ->context['status'],
        );
    }

    public function test_shelter_case_update_route_has_instance_defaults_and_no_delete_route(): void
    {
        $route = Route::getRoutes()->getByName('shelter.cases.updates.store');

        $this->assertNotNull($route);
        $this->assertSame('shelter/cases/{case}/updates', $route->uri());
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('shelter-rescue', $route->defaults['subCoreKey'] ?? null);
        $this->assertSame('cases', $route->defaults['moduleKey'] ?? null);
        $this->assertContains(
            'service.selected:shelter-rescue',
            $route->gatherMiddleware(),
        );
        $this->assertContains(
            'plugin.enabled:shelter-rescue,cases',
            $route->gatherMiddleware(),
        );
        $this->assertFalse(Route::getRoutes()->hasNamedRoute(
            'shelter.cases.destroy',
        ));
    }

    public function test_shelter_case_and_child_routes_use_non_disclosing_visibility_for_every_record_boundary(): void
    {
        Notification::fake();
        $actor = User::factory()->create();
        $owner = User::factory()->create();
        $petCarePet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Cross-domain case pet',
            'species' => 'cat',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);
        $apesCase = ShelterCase::query()->create([
            'sub_core_key' => ShelterCase::SUB_CORE_APES_CIC,
            'user_id' => $owner->id,
            'category' => 'general',
            'priority' => 'medium',
            'status' => 'open',
            'title' => 'APES CIC case ID',
            'details' => 'Must never enter Shelter policy evaluation.',
        ]);
        $crossDomainCase = ShelterCase::query()->create([
            'sub_core_key' => ShelterCase::SUB_CORE_SHELTER_RESCUE,
            'pet_profile_id' => $petCarePet->id,
            'user_id' => $owner->id,
            'case_type' => 'rescue',
            'status' => 'open',
            'title' => 'Pet Care linked Shelter case',
            'details' => 'Must be hidden.',
        ]);
        $missingPetCase = $this->shelterCaseFor($owner, 'Missing pet case');
        PetProfile::query()
            ->whereKey($missingPetCase->pet_profile_id)
            ->update(['name' => 'Detached still-valid pet choice']);
        DB::table('shelter_cases')
            ->where('id', $missingPetCase->id)
            ->update(['pet_profile_id' => null]);
        $foreignOwnerCase = $this->shelterCaseFor(
            $owner,
            'Foreign owner Shelter case',
        );

        $this->actingAs($actor);
        foreach ([999999, $apesCase, $crossDomainCase, $missingPetCase, $foreignOwnerCase] as $case) {
            $this->get(route('shelter.cases.show', $case))
                ->assertNotFound();
            $this->put(route('shelter.cases.update', $case), [
                'details' => 'Attempted cross-boundary update.',
            ])->assertNotFound();
            $this->post(route('shelter.cases.updates.store', $case), [
                'body' => 'Attempted cross-boundary child update.',
            ])->assertNotFound();
        }

        $this->assertDatabaseCount('case_updates', 0);

        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $this->actingAs($staff)
            ->get(route('shelter.cases.index'))
            ->assertOk()
            ->assertSee($foreignOwnerCase->title)
            ->assertDontSee($crossDomainCase->title)
            ->assertDontSee($missingPetCase->title)
            ->assertDontSee($apesCase->title);
        $this->get(route('shelter.cases.show', $foreignOwnerCase))
            ->assertOk();
        $this->put(route('shelter.cases.update', $foreignOwnerCase), [
            'title' => 'Staff-visible foreign Shelter case',
        ])->assertRedirect(route('shelter.cases.show', $foreignOwnerCase));
        $this->post(route('shelter.cases.updates.store', $foreignOwnerCase), [
            'body' => 'Exact staff update on a foreign-owner Shelter case.',
            'visibility' => 'internal',
        ])->assertRedirect(route('shelter.cases.show', $foreignOwnerCase));
        $this->assertDatabaseHas('case_updates', [
            'shelter_case_id' => $foreignOwnerCase->id,
            'body' => 'Exact staff update on a foreign-owner Shelter case.',
            'visibility' => 'internal',
        ]);
    }

    public function test_owner_and_cross_owner_metadata_updates_use_exact_update_and_close_permissions(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $case = $this->shelterCaseFor($owner, 'Permission transition case');
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'shelter-rescue.cases.update-own',
        );

        $this->actingAs($owner->fresh())
            ->get(route('shelter.cases.show', $case))
            ->assertOk()
            ->assertSee('Status: Open')
            ->assertSee('Initial Shelter case details.')
            ->assertDontSee('action="'.route('shelter.cases.update', $case).'"', false);
        $this->put(route('shelter.cases.update', $case), [
            'details' => 'Unauthorized owner details.',
        ])->assertForbidden();

        $this->grantRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'shelter-rescue.cases.update-own',
        );
        $this->actingAs($owner->fresh())
            ->get(route('shelter.cases.show', $case))
            ->assertOk()
            ->assertSee('name="case_type"', false)
            ->assertSee('name="title"', false);
        $this->put(route('shelter.cases.update', $case), [
            'case_type' => 'fostering',
            'title' => 'Owner-updated Shelter case',
            'details' => 'Authorized owner details.',
            'status' => 'in_review',
        ])->assertRedirect(route('shelter.cases.show', $case));
        $case->refresh();
        $this->assertSame('fostering', $case->case_type);
        $this->assertSame('Owner-updated Shelter case', $case->title);
        $this->assertSame('Authorized owner details.', $case->details);
        $this->assertSame('in_review', $case->status);

        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'shelter-rescue.cases.update-all',
        );
        $this->actingAs($staff->fresh())
            ->put(route('shelter.cases.update', $case), [
                'title' => 'Unauthorized cross-owner title',
            ])->assertForbidden();
        $this->grantRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'shelter-rescue.cases.update-all',
        );
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'shelter-rescue.cases.close',
        );
        $this->actingAs($staff->fresh())
            ->put(route('shelter.cases.update', $case), [
                'status' => 'closed',
            ])->assertForbidden();
        $this->grantRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'shelter-rescue.cases.close',
        );
        $this->actingAs($staff->fresh())
            ->put(route('shelter.cases.update', $case), [
                'status' => 'closed',
            ])->assertRedirect(route('shelter.cases.show', $case));
        $this->assertSame('closed', $case->fresh()->status);
        $this->assertNotNull($case->fresh()->closed_at);

        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'shelter-rescue.cases.close',
        );
        $this->actingAs($staff->fresh())
            ->put(route('shelter.cases.update', $case), [
                'status' => 'open',
            ])->assertForbidden();
        $this->grantRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'shelter-rescue.cases.close',
        );
        $this->actingAs($staff->fresh())
            ->put(route('shelter.cases.update', $case), [
                'status' => 'open',
            ])->assertRedirect(route('shelter.cases.show', $case));
        $case->refresh();
        $this->assertSame('open', $case->status);
        $this->assertNull($case->closed_at);
        $audit = AuditLog::query()
            ->where('event', 'shelter.case.updated')
            ->where('auditable_id', $case->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($staff->id, $audit->user_id);
        $this->assertSame('case', $audit->auditable_type);
    }

    public function test_close_only_actor_can_use_only_closed_boundary_transitions(): void
    {
        $owner = User::factory()->create();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $case = $this->shelterCaseFor($owner, 'Close-only transition case');
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'shelter-rescue.cases.update-all',
        );

        $this->actingAs($actor->fresh())
            ->get(route('shelter.cases.show', $case))
            ->assertOk()
            ->assertSee('id="case-metadata-form"', false)
            ->assertSee('name="status"', false)
            ->assertSee('<option value="closed"', false)
            ->assertDontSee('<option value="in_review"', false)
            ->assertDontSee('name="case_type"', false)
            ->assertDontSee('name="title"', false)
            ->assertDontSee('name="details"', false);
        $this->put(route('shelter.cases.update', $case), [
            'status' => 'closed',
        ])->assertRedirect(route('shelter.cases.show', $case));
        $this->assertSame('closed', $case->fresh()->status);
        $this->assertNotNull($case->fresh()->closed_at);

        $this->put(route('shelter.cases.update', $case), [
            'status' => 'open',
        ])->assertRedirect(route('shelter.cases.show', $case));
        $this->assertSame('open', $case->fresh()->status);
        $this->assertNull($case->fresh()->closed_at);
    }

    public function test_assignment_requires_exact_actor_permission_and_exact_eligible_assignees(): void
    {
        $owner = User::factory()->create(['name' => 'Case owner']);
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create(['name' => 'Assignment actor']);
        $eligible = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create(['name' => 'Exact Shelter case assignee']);
        $viewlessStaff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create(['name' => 'Wrong namespace staff']);
        $suspendedStaff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create([
                'name' => 'Suspended Shelter staff',
                'suspended_at' => now(),
            ]);
        $permissionOnlyNonstaff = User::factory()
            ->customRole('permission-only-case-viewer')
            ->create(['name' => 'Permission-only nonstaff']);
        $this->grantRolePermission(
            'permission-only-case-viewer',
            'shelter-rescue.cases.view-all',
        );
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'shelter-rescue.cases.view-all',
        );
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_ADMINISTRATOR,
            'shelter-rescue.cases.update-all',
        );
        $case = $this->shelterCaseFor($owner, 'Exact assignment case');

        $response = $this->actingAs($actor)
            ->get(route('shelter.cases.show', $case))
            ->assertOk()
            ->assertSee('id="case-assignment-form"', false)
            ->assertSee($eligible->name)
            ->assertDontSee($viewlessStaff->name)
            ->assertDontSee($suspendedStaff->name)
            ->assertDontSee($permissionOnlyNonstaff->name);
        $this->assertSame(1, preg_match(
            '/<form[^>]*id="case-assignment-form"[^>]*>(.*?)<\/form>/s',
            $response->getContent(),
            $assignmentForm,
        ));
        $this->assertStringContainsString(
            'name="assigned_to"',
            $assignmentForm[1],
        );
        $this->assertStringNotContainsString(
            'name="status"',
            $assignmentForm[1],
        );

        $this->put(route('shelter.cases.update', $case), [
            'assigned_to' => $eligible->id,
        ])->assertRedirect(route('shelter.cases.show', $case));
        $this->assertSame($eligible->id, $case->fresh()->assigned_to);

        foreach ([$viewlessStaff, $suspendedStaff, $permissionOnlyNonstaff] as $invalid) {
            $this->put(route('shelter.cases.update', $case), [
                'assigned_to' => $invalid->id,
            ])->assertSessionHasErrors('assigned_to');
            $this->assertSame($eligible->id, $case->fresh()->assigned_to);
        }

        $this->removeRolePermission(
            AuthorizationProfile::ROLE_ADMINISTRATOR,
            'shelter-rescue.cases.assign',
        );
        $this->actingAs($actor->fresh())
            ->put(route('shelter.cases.update', $case), [
                'assigned_to' => null,
            ])->assertForbidden();
        $this->assertSame($eligible->id, $case->fresh()->assigned_to);
    }

    public function test_unauthorized_assignment_hides_invalid_target_details_and_records_denials(): void
    {
        $owner = User::factory()->create();
        $validTarget = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $case = $this->shelterCaseFor($owner, 'Assignment probe case');
        $this->actingAs($owner);

        $valid = $this->put(route('shelter.cases.update', $case), [
            'assigned_to' => $validTarget->id,
        ]);
        $invalid = $this->put(route('shelter.cases.update', $case), [
            'assigned_to' => 999999,
        ]);

        $valid->assertForbidden();
        $invalid->assertForbidden();
        $this->assertSame($valid->getContent(), $invalid->getContent());
        $this->assertNull($case->fresh()->assigned_to);
        $audits = AuditLog::query()
            ->where('event', 'authorization.assignment_denied')
            ->where('auditable_type', 'case')
            ->where('auditable_id', $case->id)
            ->get();
        $this->assertCount(2, $audits);
        foreach ($audits as $audit) {
            $keys = array_keys($audit->context);
            sort($keys);
            $this->assertSame(
                ['actor_id', 'method', 'reason_code', 'route_name'],
                $keys,
            );
        }
        $this->assertStringNotContainsString(
            (string) $validTarget->id,
            $audits->pluck('context')->toJson(),
        );
        $this->assertStringNotContainsString(
            '999999',
            $audits->pluck('context')->toJson(),
        );
    }

    public function test_normal_case_notifications_use_only_exact_eligible_staff_and_authorized_owner(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['name' => 'Notification case owner']);
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create(['name' => 'Notification actor']);
        $eligible = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create(['name' => 'Exact notification staff']);
        $wrongNamespaceStaff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create(['name' => 'APES-only notification staff']);
        $suspendedStaff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create(['suspended_at' => now()]);
        $permissionOnlyNonstaff = User::factory()
            ->customRole('permission-only-case-recipient')
            ->create();
        $this->grantRolePermission(
            'permission-only-case-recipient',
            'shelter-rescue.cases.view-all',
        );
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'shelter-rescue.cases.view-all',
        );
        $pet = $this->shelterPetFor($owner, 'Notification owner pet');

        $this->actingAs($actor)
            ->post(route('shelter.cases.store'), [
                'pet_profile_id' => $pet->id,
                'case_type' => 'rescue',
                'title' => 'Exact notification case',
                'details' => 'Sensitive normal-case details.',
            ])->assertRedirect();
        $case = ShelterCase::query()
            ->where('title', 'Exact notification case')
            ->firstOrFail();

        $this->removeRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'shelter-rescue.cases.view-own',
        );
        $this->actingAs($actor->fresh())
            ->put(route('shelter.cases.update', $case), [
                'title' => 'Exact notification case updated',
            ])->assertRedirect(route('shelter.cases.show', $case));

        Notification::assertSentToTimes(
            $eligible,
            ShelterCaseUpdatedNotification::class,
            2,
        );
        Notification::assertSentToTimes(
            $owner,
            ShelterCaseUpdatedNotification::class,
            1,
        );
        Notification::assertNothingSentTo($actor);
        Notification::assertNothingSentTo($wrongNamespaceStaff);
        Notification::assertNothingSentTo($suspendedStaff);
        Notification::assertNothingSentTo($permissionOnlyNonstaff);
        Notification::assertSentTo(
            $owner,
            ShelterCaseUpdatedNotification::class,
            function (ShelterCaseUpdatedNotification $notification) use ($owner): bool {
                return ! str_contains(
                    json_encode(
                        $notification->toArray($owner),
                        JSON_THROW_ON_ERROR,
                    ),
                    'Sensitive normal-case details.',
                );
            },
        );
        foreach (AuditLog::query()
            ->where('auditable_type', 'case')
            ->where('auditable_id', $case->id)
            ->get() as $audit) {
            $this->assertStringNotContainsString(
                'Sensitive normal-case details.',
                json_encode($audit->context, JSON_THROW_ON_ERROR),
            );
        }
    }

    public function test_owner_case_updates_require_comment_are_public_and_refuse_closed_cases(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-14 10:00:00');
        $owner = User::factory()->create();
        $case = $this->shelterCaseFor($owner, 'Owner update case');
        $case->timestamps = false;
        $case->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
        $case->timestamps = true;
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'shelter-rescue.cases.comment-own',
        );

        $this->actingAs($owner->fresh())
            ->get(route('shelter.cases.show', $case))
            ->assertOk()
            ->assertDontSee(
                'action="'.route('shelter.cases.updates.store', $case).'"',
                false,
            );
        $this->post(route('shelter.cases.updates.store', $case), [
            'body' => 'Forbidden owner update.',
        ])->assertForbidden();

        $this->grantRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'shelter-rescue.cases.comment-own',
        );
        $this->actingAs($owner->fresh())
            ->get(route('shelter.cases.show', $case))
            ->assertOk()
            ->assertSee(
                'action="'.route('shelter.cases.updates.store', $case).'"',
                false,
            )
            ->assertDontSee('name="visibility"', false);

        Carbon::setTestNow('2026-08-14 11:00:00');
        $body = 'Owner-facing Shelter update body.';
        $this->post(route('shelter.cases.updates.store', $case), [
            'body' => $body,
            'visibility' => 'internal',
        ])->assertRedirect(route('shelter.cases.show', $case));
        $this->assertDatabaseHas('case_updates', [
            'shelter_case_id' => $case->id,
            'user_id' => $owner->id,
            'body' => $body,
            'visibility' => 'public',
        ]);
        $this->assertTrue($case->fresh()->updated_at->equalTo(now()));
        $audit = AuditLog::query()
            ->where('event', 'shelter.case.update_added')
            ->where('auditable_id', $case->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame([
            'sub_core_key' => ShelterCase::SUB_CORE_SHELTER_RESCUE,
            'module_key' => 'cases',
            'visibility' => 'public',
        ], $audit->context);
        $this->assertStringNotContainsString(
            $body,
            json_encode($audit->context, JSON_THROW_ON_ERROR),
        );

        $case->update(['status' => 'closed', 'closed_at' => now()]);
        $this->post(route('shelter.cases.updates.store', $case), [
            'body' => 'Closed-case update attempt.',
        ])->assertSessionHasErrors([
            'body' => 'Reopen the case before adding another update.',
        ]);
        $this->assertSame(1, $case->updates()->count());
    }

    public function test_public_and_internal_updates_preserve_activity_privacy_and_exact_recipients(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-14 09:00:00');
        $owner = User::factory()->create(['name' => 'Update privacy owner']);
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create(['name' => 'Update privacy actor']);
        $eligible = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create(['name' => 'Exact update recipient']);
        $wrongNamespaceStaff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create(['name' => 'Wrong update namespace']);
        $suspendedStaff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create(['suspended_at' => now()]);
        $permissionOnlyNonstaff = User::factory()
            ->customRole('permission-only-update-recipient')
            ->create();
        $this->grantRolePermission(
            'permission-only-update-recipient',
            'shelter-rescue.cases.view-all',
        );
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'shelter-rescue.cases.view-all',
        );
        $case = $this->shelterCaseFor($owner, 'Update privacy case');
        $case->timestamps = false;
        $case->forceFill(['updated_at' => now()])->saveQuietly();
        $case->timestamps = true;
        $ownerActivityAt = $case->fresh()->updated_at;

        Carbon::setTestNow('2026-08-14 10:00:00');
        $internalBody = 'Private Shelter case staff note.';
        $this->actingAs($actor)
            ->post(route('shelter.cases.updates.store', $case), [
                'body' => $internalBody,
                'visibility' => 'internal',
            ])->assertRedirect(route('shelter.cases.show', $case));
        $this->assertTrue($case->fresh()->updated_at->equalTo($ownerActivityAt));
        $this->actingAs($owner)
            ->get(route('shelter.cases.show', $case))
            ->assertOk()
            ->assertDontSee($internalBody)
            ->assertDontSee('name="visibility"', false);
        $this->actingAs($actor)
            ->get(route('shelter.cases.show', $case))
            ->assertOk()
            ->assertSee($internalBody)
            ->assertSee('name="visibility"', false)
            ->assertSee('Internal staff only')
            ->assertDontSee('value="DELETE"', false);

        Carbon::setTestNow('2026-08-14 11:00:00');
        $publicBody = 'Public Shelter case progress.';
        $this->post(route('shelter.cases.updates.store', $case), [
            'body' => $publicBody,
            'visibility' => 'public',
        ])->assertRedirect(route('shelter.cases.show', $case));
        $this->assertTrue($case->fresh()->updated_at->equalTo(now()));
        $this->actingAs($owner)
            ->get(route('shelter.cases.show', $case))
            ->assertOk()
            ->assertSee($publicBody)
            ->assertDontSee($internalBody);

        $this->removeRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'shelter-rescue.cases.view-own',
        );
        Carbon::setTestNow('2026-08-14 12:00:00');
        $this->actingAs($actor->fresh())
            ->post(route('shelter.cases.updates.store', $case), [
                'body' => 'Public update after owner access was revoked.',
                'visibility' => 'public',
            ])->assertRedirect(route('shelter.cases.show', $case));

        Notification::assertSentToTimes(
            $eligible,
            ShelterCaseUpdatedNotification::class,
            3,
        );
        Notification::assertSentToTimes(
            $owner,
            ShelterCaseUpdatedNotification::class,
            1,
        );
        Notification::assertNothingSentTo($actor);
        Notification::assertNothingSentTo($wrongNamespaceStaff);
        Notification::assertNothingSentTo($suspendedStaff);
        Notification::assertNothingSentTo($permissionOnlyNonstaff);

        $audits = AuditLog::query()
            ->where('event', 'shelter.case.update_added')
            ->where('auditable_id', $case->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(3, $audits);
        $this->assertSame(
            ['internal', 'public', 'public'],
            $audits->pluck('context.visibility')->all(),
        );
        foreach ($audits as $audit) {
            $this->assertSame(
                ['sub_core_key', 'module_key', 'visibility'],
                array_keys($audit->context),
            );
            $serialized = json_encode($audit->context, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($internalBody, $serialized);
            $this->assertStringNotContainsString($publicBody, $serialized);
        }
    }

    public function test_internal_update_never_notifies_an_owner_who_is_also_eligible_staff(): void
    {
        Notification::fake();
        $owner = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $staffPeer = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $case = $this->shelterCaseFor($owner, 'Dual-role owner privacy case');

        $this->actingAs($actor)
            ->post(route('shelter.cases.updates.store', $case), [
                'body' => 'Internal update hidden from the owner role.',
                'visibility' => 'internal',
            ])->assertRedirect(route('shelter.cases.show', $case));

        Notification::assertNothingSentTo($owner);
        Notification::assertNothingSentTo($actor);
        Notification::assertSentTo(
            $staffPeer,
            ShelterCaseUpdatedNotification::class,
        );
    }

    public function test_permission_only_nonstaff_cannot_access_internal_updates(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $permissionOnlyNonstaff = User::factory()->create();
        $this->grantRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'shelter-rescue.cases.view-all',
        );
        $case = $this->shelterCaseFor($owner, 'Internal eligibility case');
        $permissionOnlyNonstaff = $permissionOnlyNonstaff->fresh();
        $this->actingAs($permissionOnlyNonstaff);

        $this->post(route('shelter.cases.updates.store', $case), [
            'body' => 'Attempted internal update.',
            'visibility' => 'internal',
        ])->assertNotFound();
        $this->assertDatabaseMissing('case_updates', [
            'shelter_case_id' => $case->id,
            'body' => 'Attempted internal update.',
        ]);

        $case->updates()->create([
            'user_id' => $owner->id,
            'body' => 'Existing internal update.',
            'visibility' => 'internal',
        ]);

        $this->get(route('shelter.cases.show', $case))->assertNotFound();
    }

    private function shelterCaseFor(
        User $owner,
        string $title = 'Shelter case fixture',
        array $attributes = [],
    ): ShelterCase {
        $pet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_SHELTER,
            'name' => $title.' pet',
            'species' => 'dog',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);

        return ShelterCase::query()->create(array_merge([
            'sub_core_key' => ShelterCase::SUB_CORE_SHELTER_RESCUE,
            'pet_profile_id' => $pet->id,
            'user_id' => $owner->id,
            'case_type' => 'rescue',
            'status' => 'open',
            'title' => $title,
            'details' => 'Initial Shelter case details.',
        ], $attributes));
    }

    private function shelterPetFor(User $owner, string $name): PetProfile
    {
        return PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_SHELTER,
            'name' => $name,
            'species' => 'dog',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);
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
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function grantRolePermission(
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
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
