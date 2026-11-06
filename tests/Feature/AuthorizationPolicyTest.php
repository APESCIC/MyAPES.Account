<?php

namespace Tests\Feature;

use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\Tickets\Models\SupportTicket;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuthorizationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_owners_keep_public_access_while_other_public_users_are_denied(): void
    {
        $owner = $this->user(User::ROLE_SERVICE_USER);
        $other = $this->user(User::ROLE_SERVICE_USER);
        [$ticket, $pet, $case, $consultation] = $this->resourcesFor($owner);

        $this->actingAsWithQaContext($owner);
        foreach ([$ticket, $pet, $case] as $resource) {
            $this->assertTrue(Gate::allows('view', $resource));
            $this->assertTrue(Gate::allows('update', $resource));
        }
        $this->assertTrue(Gate::allows('view', $consultation));
        $this->assertFalse(Gate::allows('update', $consultation));
        $this->assertFalse(Gate::allows('delete', $ticket));

        $this->actingAsWithQaContext($other);
        foreach ([$ticket, $pet, $case, $consultation] as $resource) {
            $this->assertFalse(Gate::allows('view', $resource));
            $this->assertFalse(Gate::allows('update', $resource));
        }
    }

    public function test_current_staff_context_can_access_all_resources_and_staff_actions(): void
    {
        $owner = $this->user(User::ROLE_SERVICE_USER);
        $staff = $this->user(User::ROLE_STAFF);
        [$ticket, $pet, $case, $consultation] = $this->resourcesFor($owner);
        $this->actingAsWithQaContext($staff);

        foreach ([$ticket, $pet, $case] as $resource) {
            $this->assertTrue(Gate::allows('view', $resource));
            $this->assertTrue(Gate::allows('update', $resource));
        }
        $this->assertTrue(Gate::allows('view', $consultation));
        $this->assertFalse(Gate::allows('update', $consultation));
        $this->assertTrue(Gate::allows('assign', $consultation));
        $this->assertTrue(Gate::allows('close', $consultation));
        $this->assertTrue(Gate::allows('delete', $ticket));
    }

    public function test_pet_care_pet_profile_policy_requires_matching_permission_pairs_and_enabled_installation(): void
    {
        $owner = $this->user(User::ROLE_SERVICE_USER);
        $pet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Pet Care policy profile',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);

        $this->actingAsWithQaContext($owner);
        $this->assertTrue(Gate::allows('view', $pet));
        $this->assertTrue(Gate::allows('update', $pet));

        $this->removeRolePermission(
            AuthorizationProfile::ROLE_SERVICE_USER,
            'pet-care-clinic.pet-profiles.update-own',
        );
        $owner = $owner->fresh();
        $this->actingAsWithQaContext($owner);
        $this->assertTrue(Gate::allows('view', $pet));
        $this->assertFalse(Gate::allows('update', $pet));

        ModuleInstallation::query()
            ->where('sub_core_key', 'pet-care-clinic')
            ->where('module_key', 'pet-profiles')
            ->update(['enabled' => false, 'disabled_at' => now()]);
        $this->assertFalse(Gate::allows('view', $pet));
        $this->assertFalse(Gate::allows('update', $pet));
    }

    public function test_shelter_case_comment_only_owner_cannot_update_case_metadata_or_status(): void
    {
        $owner = $this->user(User::ROLE_SERVICE_USER);
        [, , $case] = $this->resourcesFor($owner);
        $serviceUserRole = Role::query()
            ->where('guard_name', 'web')
            ->where('name', AuthorizationProfile::ROLE_SERVICE_USER)
            ->firstOrFail();
        $updateOwn = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'shelter-rescue.cases.update-own')
            ->firstOrFail();
        $serviceUserRole->permissions()->detach($updateOwn->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAsWithQaContext($owner->fresh());
        $this->assertTrue($owner->fresh()->can('shelter-rescue.cases.comment-own'));
        $this->assertFalse($owner->fresh()->can('shelter-rescue.cases.update-own'));

        $this->put(route('shelter.cases.update', $case), [
            'status' => 'in_review',
            'details' => 'An owner comment must not update the case record.',
        ])->assertForbidden();

        $this->assertSame('open', $case->fresh()->status);
    }

    public function test_shelter_assignment_capability_only_allows_a_pure_assignment(): void
    {
        $owner = $this->user(User::ROLE_SERVICE_USER);
        $staff = $this->user(User::ROLE_STAFF);
        $replacement = $this->user(User::ROLE_STAFF);
        [, , $case] = $this->resourcesFor($owner);
        $this->removeRolePermission(AuthorizationProfile::ROLE_STAFF, 'shelter-rescue.cases.update-all');

        $this->actingAsWithQaContext($staff->fresh());
        $this->assertFalse(Gate::allows('update', $case));
        $this->put(route('shelter.cases.update', $case), ['assigned_to' => $replacement->id])
            ->assertRedirect(route('shelter.cases.show', $case));
        $this->assertSame($replacement->id, $case->fresh()->assigned_to);

        $this->put(route('shelter.cases.update', $case), ['details' => 'Unauthorized detail change.'])
            ->assertForbidden();
        $this->put(route('shelter.cases.update', $case), ['status' => 'in_review'])
            ->assertForbidden();
        $this->assertSame('open', $case->fresh()->status);
        $this->assertNull($case->fresh()->details);
    }

    public function test_shelter_close_capability_only_allows_pure_lifecycle_requests(): void
    {
        $owner = $this->user(User::ROLE_SERVICE_USER);
        $staff = $this->user(User::ROLE_STAFF);
        [, , $case] = $this->resourcesFor($owner);
        $this->removeRolePermission(AuthorizationProfile::ROLE_STAFF, 'shelter-rescue.cases.update-all');

        $this->actingAsWithQaContext($staff->fresh());
        $this->assertFalse(Gate::allows('update', $case));
        $this->put(route('shelter.cases.update', $case), ['status' => 'closed'])
            ->assertRedirect(route('shelter.cases.show', $case));
        $this->put(route('shelter.cases.update', $case), ['status' => 'open'])
            ->assertRedirect(route('shelter.cases.show', $case));
        $this->put(route('shelter.cases.update', $case), ['details' => 'Unauthorized detail change.'])
            ->assertForbidden();
        $this->put(route('shelter.cases.update', $case), ['status' => 'in_review'])
            ->assertForbidden();
    }

    public function test_shelter_status_control_hides_closed_boundary_transitions_without_close_permission(): void
    {
        $owner = $this->user(User::ROLE_SERVICE_USER);
        $staff = $this->user(User::ROLE_STAFF);
        [, $pet, $activeCase] = $this->resourcesFor($owner);
        $closedCase = ShelterCase::query()->create([
            'pet_profile_id' => $pet->id,
            'user_id' => $owner->id,
            'case_type' => 'rescue',
            'status' => 'closed',
            'title' => 'Closed policy case',
            'closed_at' => now(),
        ]);
        $this->removeRolePermission(AuthorizationProfile::ROLE_STAFF, 'shelter-rescue.cases.close');

        $this->actingAsWithQaContext($staff->fresh());
        $this->get(route('shelter.cases.show', $activeCase))
            ->assertOk()
            ->assertSee('name="status"', false)
            ->assertSee('<option value="open"', false)
            ->assertSee('<option value="in_review"', false)
            ->assertDontSee('<option value="closed"', false)
            ->assertSee('name="details"', false);
        $this->put(route('shelter.cases.update', $activeCase), [
            'status' => 'in_review',
        ])->assertRedirect(route('shelter.cases.show', $activeCase));
        $this->assertSame('in_review', $activeCase->fresh()->status);

        $this->get(route('shelter.cases.show', $closedCase))
            ->assertOk()
            ->assertSee('name="status"', false)
            ->assertSee('<option value="closed"', false)
            ->assertDontSee('<option value="open"', false)
            ->assertDontSee('<option value="in_review"', false)
            ->assertSee('name="details"', false);
    }

    public function test_shelter_mixed_permitted_assignment_and_unauthorized_metadata_is_atomic(): void
    {
        Notification::fake();
        $owner = $this->user(User::ROLE_SERVICE_USER);
        $staff = $this->user(User::ROLE_STAFF);
        $replacement = $this->user(User::ROLE_STAFF);
        [, , $case] = $this->resourcesFor($owner);
        $this->removeRolePermission(AuthorizationProfile::ROLE_STAFF, 'shelter-rescue.cases.update-all');

        $this->actingAsWithQaContext($staff->fresh());
        $this->put(route('shelter.cases.update', $case), [
            'assigned_to' => $replacement->id,
            'status' => 'in_review',
            'details' => 'This request must fail as a whole.',
        ])->assertForbidden();

        $this->assertNull($case->fresh()->assigned_to);
        $this->assertNull($case->fresh()->details);
        Notification::assertNothingSent();
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'shelter.case.updated',
            'auditable_type' => 'case',
            'auditable_id' => $case->id,
        ]);
    }

    /**
     * @return array{SupportTicket, PetProfile, ShelterCase, PetCareConsultation}
     */
    private function resourcesFor(User $owner): array
    {
        $ticket = SupportTicket::query()->create([
            'user_id' => $owner->id,
            'service_area' => 'it',
            'subject' => 'Policy ticket',
            'description' => 'Policy coverage',
        ]);
        $pet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_SHELTER,
            'name' => 'Policy Pet',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);
        $case = ShelterCase::query()->create([
            'pet_profile_id' => $pet->id,
            'user_id' => $owner->id,
            'case_type' => 'rescue',
            'title' => 'Policy case',
        ]);
        $consultationPet = PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => PetProfile::DOMAIN_PETCARE,
            'name' => 'Policy Clinic Pet',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);
        $consultation = PetCareConsultation::query()->create([
            'pet_profile_id' => $consultationPet->id,
            'user_id' => $owner->id,
            'subject' => 'Policy consultation',
        ]);

        return [$ticket, $pet, $case, $consultation];
    }

    private function user(string $accessLevel): User
    {
        return User::factory()
            ->accessLevel($accessLevel)
            ->create()
            ->refresh();
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

    private function actingAsWithQaContext(User $user): void
    {
        if (! request()->hasSession()) {
            request()->setLaravelSession(app('session')->driver());
        }

        $this->actingAs($user);
        request()->session()->put([
            'myapes.authentication_method' => 'qa',
            'myapes.authorization_epoch' => $user->authorization_epoch,
        ]);
    }
}
