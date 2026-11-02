<?php

namespace Tests\Feature;

use App\Core\Accounts\DirectoryGroup;
use App\Core\Accounts\DirectoryGroupRoleMapping;
use App\Core\Accounts\Role;
use App\Core\Accounts\User;
use App\Exceptions\AuthorizationMutationDenied;
use App\Services\AuthorizationProfile;
use App\Services\DirectoryGroupMappingService;
use App\Services\DirectoryRoleSynchronizer;
use App\Support\DefaultJobRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectoryGroupMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_mapping_mutations_are_rejected_for_preset_groups(): void
    {
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $group = DirectoryGroup::query()
            ->where('name', 'myapesaccount.staff')
            ->firstOrFail();
        $role = Role::query()
            ->where('name', AuthorizationProfile::ROLE_STAFF)
            ->firstOrFail();

        $service = app(DirectoryGroupMappingService::class);

        try {
            $this->actingAs($actor);
            $service->map($actor, $group, $role);
            $this->fail('Preset directory mappings should not be mutable.');
        } catch (AuthorizationMutationDenied $exception) {
            $this->assertSame('protected_role', $exception->reasonCode);
        }

        try {
            $service->setAppEnabled($actor, $group, false);
            $this->fail('Preset directory groups should remain enabled.');
        } catch (AuthorizationMutationDenied $exception) {
            $this->assertSame('preset_groups_only', $exception->reasonCode);
        }
    }

    public function test_legacy_myapes_groups_are_not_eligible_for_mapping(): void
    {
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $group = DirectoryGroup::query()->create([
            'name' => 'myapes.staff',
            'status' => DirectoryGroup::STATUS_PRESENT,
            'app_enabled' => true,
        ]);

        $this->expectException(AuthorizationMutationDenied::class);

        app(DirectoryGroupMappingService::class)->assertManagedGroup($group);
    }

    public function test_immutable_mapping_removal_is_rejected(): void
    {
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $group = DirectoryGroup::query()
            ->where('name', 'myapesaccount.admin')
            ->firstOrFail();
        $role = Role::query()
            ->where('name', AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->firstOrFail();
        $mapping = DirectoryGroupRoleMapping::query()
            ->where('directory_group_id', $group->id)
            ->where('role_id', $role->id)
            ->firstOrFail();

        $this->actingAs($actor);

        try {
            app(DirectoryGroupMappingService::class)->remove($actor, $mapping);
            $this->fail('Immutable preset mappings should not be removable.');
        } catch (AuthorizationMutationDenied $exception) {
            $this->assertSame('immutable_mapping', $exception->reasonCode);
        }
    }

    public function test_job_role_mapping_is_mutable_without_replacing_protected_mappings(): void
    {
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $this->actingAs($actor);
        $group = DirectoryGroup::query()
            ->where('name', 'myapesaccount.staff')
            ->firstOrFail();
        $jobRole = Role::query()
            ->where('name', DefaultJobRoles::RECEPTIONIST)
            ->firstOrFail();
        $staffRole = Role::query()
            ->where('name', AuthorizationProfile::ROLE_STAFF)
            ->firstOrFail();
        $user = User::factory()
            ->accessLevel(User::ROLE_STAFF)
            ->cloudronIdentity('job-role-mapping-subject')
            ->create()
            ->refresh();

        $mapping = app(DirectoryGroupMappingService::class)->map(
            $actor,
            $group,
            $jobRole,
        );

        $this->assertFalse($mapping->is_immutable);
        $this->assertDatabaseHas('directory_group_role_mappings', [
            'directory_group_id' => $group->id,
            'role_id' => $staffRole->id,
            'is_immutable' => true,
        ]);
        $this->assertDatabaseHas('directory_group_role_mappings', [
            'directory_group_id' => $group->id,
            'role_id' => $jobRole->id,
            'is_immutable' => false,
        ]);

        $result = app(DirectoryRoleSynchronizer::class)->synchronize($user, [
            'myapesaccount.staff',
        ]);

        $this->assertTrue($result->eligible);
        $this->assertSame('staff', $result->protectedRole);
        $this->assertEqualsCanonicalizing(
            ['receptionist', 'staff'],
            $user->fresh()->roles()->pluck('name')->all(),
        );

        app(DirectoryGroupMappingService::class)->remove($actor, $mapping);

        $this->assertDatabaseMissing('directory_group_role_mappings', [
            'id' => $mapping->id,
        ]);
        $this->assertDatabaseHas('directory_group_role_mappings', [
            'directory_group_id' => $group->id,
            'role_id' => $staffRole->id,
            'is_immutable' => true,
        ]);
    }

    public function test_job_role_mapping_does_not_replace_missing_directory_eligibility(): void
    {
        $actor = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $this->actingAs($actor);
        $group = DirectoryGroup::query()->create([
            'name' => 'myapesaccount.reception',
            'status' => DirectoryGroup::STATUS_PRESENT,
            'app_enabled' => true,
        ]);
        $jobRole = Role::query()
            ->where('name', DefaultJobRoles::RECEPTIONIST)
            ->firstOrFail();
        $user = User::factory()
            ->accessLevel(User::ROLE_SERVICE_USER)
            ->cloudronIdentity('job-role-only-subject')
            ->create()
            ->refresh();

        app(DirectoryGroupMappingService::class)->map($actor, $group, $jobRole);

        $result = app(DirectoryRoleSynchronizer::class)->synchronize($user, [
            'myapesaccount.reception',
        ]);

        $this->assertFalse($result->eligible);
        $this->assertSame(
            ['service-user'],
            $user->fresh()->roles()->pluck('name')->all(),
        );
        $this->assertDatabaseMissing('role_sources', [
            'user_id' => $user->id,
            'role_id' => $jobRole->id,
        ]);
    }
}
