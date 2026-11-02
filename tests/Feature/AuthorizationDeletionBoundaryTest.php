<?php

namespace Tests\Feature;

use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Services\AuthorizationRoleMaterializer;
use App\Support\AuthorizationCompatibilityDatabaseGuard;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthorizationDeletionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_eloquent_user_deletion_removes_provenance_and_effective_roles(): void
    {
        $user = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'deletion-reviewer',
            'guard_name' => 'web',
        ]);
        app(AuthorizationRoleMaterializer::class)->grant(
            $user,
            $role,
            RoleSource::SOURCE_LOCAL,
            actor: $user,
        );

        $this->assertTrue($user->delete());

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('role_sources', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('model_has_roles', [
            'model_type' => 'user',
            'model_id' => $user->id,
        ]);
    }

    public function test_query_level_user_deletion_cleans_polymorphic_pivots(): void
    {
        $user = User::factory()->create();
        $permission = Permission::query()
            ->where('name', 'staff.access')
            ->firstOrFail();
        $guard = app(AuthorizationCompatibilityDatabaseGuard::class);
        $guard->drop();
        DB::table('model_has_permissions')->insert([
            'permission_id' => $permission->id,
            'model_type' => 'user',
            'model_id' => $user->id,
        ]);
        $guard->install();

        $this->assertSame(
            1,
            User::query()->whereKey($user->id)->delete(),
        );

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('role_sources', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('model_has_roles', [
            'model_type' => 'user',
            'model_id' => $user->id,
        ]);
        $this->assertDatabaseMissing('model_has_permissions', [
            'model_type' => 'user',
            'model_id' => $user->id,
        ]);
    }

    public function test_unassigned_custom_role_deletion_is_allowed(): void
    {
        $role = Role::query()->create([
            'name' => 'unused-custom-role',
            'guard_name' => 'web',
        ]);

        $this->assertTrue($role->delete());
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_assigned_custom_role_deletion_is_rejected_by_domain_policy(): void
    {
        $user = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'assigned-custom-role',
            'guard_name' => 'web',
        ]);
        app(AuthorizationRoleMaterializer::class)->grant(
            $user,
            $role,
            RoleSource::SOURCE_LOCAL,
            actor: $user,
        );

        try {
            $role->delete();
            $this->fail('The database accepted deletion of an assigned role.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'Assigned authorization roles cannot be deleted.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
        $this->assertDatabaseHas('role_sources', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);
        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $role->id,
            'model_type' => 'user',
            'model_id' => $user->id,
        ]);
    }

    public function test_protected_role_deletion_is_rejected_by_domain_policy(): void
    {
        $role = Role::query()
            ->where('name', 'administrator')
            ->firstOrFail();

        try {
            $role->delete();
            $this->fail('The database accepted deletion of a protected role.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'Protected authorization roles cannot be deleted.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'is_protected' => true,
        ]);
    }

    /**
     * @param  array<string, bool|string>  $mutation
     */
    #[DataProvider('protectedRoleMutationProvider')]
    public function test_eloquent_cannot_mutate_a_protected_role_before_delete(
        string $roleName,
        array $mutation,
    ): void {
        $role = Role::query()
            ->where('name', $roleName)
            ->where('guard_name', 'web')
            ->firstOrFail();
        $updateRejected = false;

        try {
            $role->forceFill($mutation)->save();
        } catch (QueryException $exception) {
            $updateRejected = true;
            $this->assertStringContainsString(
                'Protected authorization roles are immutable.',
                $exception->getMessage(),
            );
        }

        $deleteRejected = false;

        try {
            $role->refresh()->delete();
        } catch (QueryException $exception) {
            $deleteRejected = true;
            $this->assertStringContainsString(
                'Protected authorization roles cannot be deleted.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue($updateRejected, 'The Eloquent role update was accepted.');
        $this->assertTrue($deleteRejected, 'The protected role delete was accepted.');
        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => $roleName,
            'guard_name' => 'web',
            'is_protected' => true,
        ]);
    }

    /**
     * @param  array<string, bool|string>  $mutation
     */
    #[DataProvider('protectedRoleMutationProvider')]
    public function test_query_builder_cannot_mutate_a_protected_role_before_delete(
        string $roleName,
        array $mutation,
    ): void {
        $role = Role::query()
            ->where('name', $roleName)
            ->where('guard_name', 'web')
            ->firstOrFail();
        $updateRejected = false;

        try {
            Role::query()->whereKey($role->id)->update($mutation);
        } catch (QueryException $exception) {
            $updateRejected = true;
            $this->assertStringContainsString(
                'Protected authorization roles are immutable.',
                $exception->getMessage(),
            );
        }

        $deleteRejected = false;

        try {
            $role->refresh()->delete();
        } catch (QueryException $exception) {
            $deleteRejected = true;
            $this->assertStringContainsString(
                'Protected authorization roles cannot be deleted.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue($updateRejected, 'The query-level role update was accepted.');
        $this->assertTrue($deleteRejected, 'The protected role delete was accepted.');
        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => $roleName,
            'guard_name' => 'web',
            'is_protected' => true,
        ]);
    }

    public function test_custom_roles_remain_mutable(): void
    {
        $role = Role::query()->create([
            'name' => 'mutable-custom-role',
            'guard_name' => 'web',
        ]);

        $this->assertTrue($role->forceFill([
            'name' => 'renamed-custom-role',
        ])->save());
        $this->assertSame(
            1,
            Role::query()->whereKey($role->id)->update([
                'guard_name' => 'api',
            ]),
        );

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'renamed-custom-role',
            'guard_name' => 'api',
            'is_protected' => false,
        ]);
    }

    /**
     * @return array<string, array{string, array<string, bool|string>}>
     */
    public static function protectedRoleMutationProvider(): array
    {
        return [
            'service user unprotect' => ['service-user', ['is_protected' => false]],
            'service user rename' => ['service-user', ['name' => 'renamed-service-user']],
            'service user guard' => ['service-user', ['guard_name' => 'api']],
            'service user timestamp' => ['service-user', ['updated_at' => '2027-01-01 00:00:00']],
            'staff unprotect' => ['staff', ['is_protected' => false]],
            'staff rename' => ['staff', ['name' => 'renamed-staff']],
            'staff guard' => ['staff', ['guard_name' => 'api']],
            'staff timestamp' => ['staff', ['updated_at' => '2027-01-01 00:00:00']],
            'administrator unprotect' => ['administrator', ['is_protected' => false]],
            'administrator rename' => ['administrator', ['name' => 'renamed-administrator']],
            'administrator guard' => ['administrator', ['guard_name' => 'api']],
            'administrator timestamp' => ['administrator', ['updated_at' => '2027-01-01 00:00:00']],
            'super admin unprotect' => ['administrator', ['is_protected' => false]],
            'super admin rename' => ['administrator', ['name' => 'renamed-super-admin']],
            'super admin guard' => ['administrator', ['guard_name' => 'api']],
            'super admin timestamp' => ['administrator', ['updated_at' => '2027-01-01 00:00:00']],
        ];
    }
}
