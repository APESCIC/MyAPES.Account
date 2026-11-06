<?php

namespace Tests\Feature;

use App\Core\Accounts\DirectoryGroup;
use App\Core\Accounts\DirectoryGroupRoleMapping;
use App\Core\Accounts\Role;
use App\Core\Accounts\User;
use App\Services\AuthorizationMetadataSynchronizer;
use App\Services\AuthorizationProfile;
use App\Services\DirectoryRoleSynchronizer;
use App\Support\DefaultJobRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesClinicalPersonas;
use Tests\TestCase;

class VetJobRolesTest extends TestCase
{
    use CreatesClinicalPersonas;
    use RefreshDatabase;

    public function test_vet_and_vet_nurse_are_seeded_job_roles_with_mutable_cloudron_mappings(): void
    {
        foreach ([
            DefaultJobRoles::VET => 'myapesaccount.vet',
            DefaultJobRoles::VET_NURSE => 'myapesaccount.vetnurse',
        ] as $roleName => $groupName) {
            $role = Role::query()->where('guard_name', 'web')->where('name', $roleName)->firstOrFail();
            $group = DirectoryGroup::query()->where('name', $groupName)->firstOrFail();

            $this->assertFalse((bool) $role->is_protected);
            $this->assertSame(
                [false],
                DirectoryGroupRoleMapping::query()
                    ->whereBelongsTo($group)
                    ->whereBelongsTo($role)
                    ->pluck('is_immutable')
                    ->map(static fn (mixed $value): bool => (bool) $value)
                    ->all(),
            );
        }

        $this->assertNotContains('myapesaccount.vet', config('myapes.directory.required_groups'));
        $this->assertNotContains('myapesaccount.vetnurse', config('myapes.directory.required_groups'));
    }

    public function test_removed_seeded_mapping_is_not_recreated_by_a_later_sync(): void
    {
        $role = Role::query()->where('name', DefaultJobRoles::VET)->firstOrFail();
        DirectoryGroupRoleMapping::query()->whereBelongsTo($role)->delete();

        app(AuthorizationMetadataSynchronizer::class)->synchronize();

        $this->assertFalse(DirectoryGroupRoleMapping::query()->whereBelongsTo($role)->exists());
    }

    public function test_cloudron_groups_grant_the_job_roles_alongside_a_staff_group(): void
    {
        $synchronizer = app(DirectoryRoleSynchronizer::class);
        $vet = User::factory()->create();
        $nurse = User::factory()->create();
        $vetWithoutStaff = User::factory()->create();

        $synchronizer->synchronize($vet, ['myapesaccount.staff', 'myapesaccount.vet']);
        $synchronizer->synchronize($nurse, ['myapesaccount.staff', 'myapesaccount.vetnurse']);
        $synchronizer->synchronize($vetWithoutStaff, ['myapesaccount.vet']);

        $this->assertTrue($vet->fresh()->hasRole(DefaultJobRoles::VET));
        $this->assertTrue($nurse->fresh()->hasRole(DefaultJobRoles::VET_NURSE));
        $this->assertFalse($vetWithoutStaff->fresh()->hasRole(DefaultJobRoles::VET));
    }

    public function test_vet_nurse_has_the_same_default_permissions_as_vet(): void
    {
        $this->assertSame(
            DefaultJobRoles::defaultPermissions(DefaultJobRoles::VET),
            DefaultJobRoles::defaultPermissions(DefaultJobRoles::VET_NURSE),
        );
        $this->assertSame(
            Role::query()->where('name', DefaultJobRoles::VET)->firstOrFail()->permissions->pluck('name')->sort()->values()->all(),
            Role::query()->where('name', DefaultJobRoles::VET_NURSE)->firstOrFail()->permissions->pluck('name')->sort()->values()->all(),
        );
    }

    public function test_staff_users_list_shows_a_clinical_badge(): void
    {
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $this->vetStaff(['name' => 'Vera Vet']);
        $this->vetNurseStaff(['name' => 'Nina Nurse']);
        User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create(['name' => 'Sam Staff']);

        $content = $this->actingAs($administrator)
            ->get(route('admin.users.index', ['account_type' => 'staff']))
            ->assertOk()
            ->assertSee('<span class="status" data-badge="clinical">Vet</span>', false)
            ->assertSee('<span class="status" data-badge="clinical">Vet Nurse</span>', false)
            ->getContent();

        $this->assertSame(2, substr_count($content, 'data-badge="clinical"'));
    }

    public function test_admins_can_assign_the_vet_role_by_hand(): void
    {
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $role = Role::query()->where('name', DefaultJobRoles::VET)->firstOrFail();

        $this->actingAs($administrator)
            ->put(route('admin.users.roles.update', $staff), ['roles' => [$role->id]])
            ->assertRedirect();

        $this->assertTrue($staff->fresh()->hasRole(DefaultJobRoles::VET));
    }
}
