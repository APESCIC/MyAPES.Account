<?php

namespace Tests\Feature;

use App\Core\Accounts\AuthorizationState;
use App\Core\Accounts\DirectoryGroupRoleMapping;
use App\Core\Accounts\Permission;
use App\Core\Accounts\PermissionSource;
use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Support\DefaultJobRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthorizationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_authorization_metadata_is_not_mass_assignable(): void
    {
        $state = new AuthorizationState;
        $source = new RoleSource;
        $mapping = new DirectoryGroupRoleMapping;
        $role = new Role;
        $permission = new Permission;
        $permissionSource = new PermissionSource;

        $this->assertFalse($state->isFillable('authorization_epoch'));
        $this->assertFalse($state->isFillable('cutover_completed_at'));
        $this->assertFalse($state->isFillable('directory_sync_owner_token'));
        $this->assertFalse($state->isFillable('directory_sync_expires_at'));
        $this->assertFalse($source->isFillable('source'));
        $this->assertFalse($source->isFillable('source_key'));
        $this->assertFalse($source->isFillable('directory_group_id'));
        $this->assertFalse($source->isFillable('granted_by'));
        $this->assertFalse($mapping->isFillable('directory_group_id'));
        $this->assertFalse($mapping->isFillable('role_id'));
        $this->assertFalse($mapping->isFillable('is_immutable'));
        $this->assertFalse($role->isFillable('is_protected'));
        $this->assertFalse($permission->isFillable('is_code_owned'));
        $this->assertFalse($permissionSource->isFillable('source'));
        $this->assertFalse($permissionSource->isFillable('source_key'));
        $this->assertFalse($permissionSource->isFillable('team_id'));
        $this->assertFalse($permissionSource->isFillable('granted_by'));
    }

    public function test_fresh_install_cuts_over_to_the_portable_authorization_schema(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'identity_type',
            'legacy_access_level',
            'authorization_epoch',
            'suspended_at',
            'suspended_by',
            'suspension_reason',
        ]));
        $this->assertFalse(Schema::hasColumn('users', 'role'));

        foreach ([
            'roles',
            'permissions',
            'role_has_permissions',
            'model_has_roles',
            'model_has_permissions',
            'authorization_states',
            'directory_groups',
            'directory_sync_runs',
            'directory_group_role_mappings',
            'role_sources',
            'permission_sources',
        ] as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Expected authorization table [{$table}] to exist.",
            );
        }
        $this->assertTrue(Schema::hasColumn('roles', 'team_id'));
        $this->assertTrue(Schema::hasColumn('model_has_roles', 'team_id'));
        $this->assertTrue(Schema::hasColumn('model_has_permissions', 'team_id'));
        $this->assertTrue($this->columnIsNullable('roles', 'team_id'));
        $this->assertTrue($this->columnIsNullable('model_has_roles', 'team_id'));
        $this->assertTrue(
            $this->columnIsNullable('model_has_permissions', 'team_id'),
        );

        $this->assertSame(1, DB::table('authorization_states')->count());
        $this->assertDatabaseHas('authorization_states', [
            'id' => 1,
            'authorization_epoch' => 1,
        ]);
        $this->assertTrue(Schema::hasColumns('authorization_states', [
            'session_cutover_completed_at',
            'directory_sync_owner_token',
            'directory_sync_expires_at',
        ]));
        $this->assertTrue(Schema::hasColumns('directory_sync_runs', [
            'source',
            'status',
            'queue_job_uuid',
            'queue_attempt',
            'lease_owner_token',
            'started_at',
            'finished_at',
            'groups_seen',
            'groups_missing',
            'error_code',
        ]));
        $this->assertTrue(Schema::hasIndex(
            'directory_sync_runs',
            ['queue_job_uuid', 'queue_attempt'],
            'unique',
        ));
        foreach ([
            'roles',
            'permissions',
            'authorization_states',
            'directory_groups',
            'directory_sync_runs',
            'directory_group_role_mappings',
            'role_sources',
            'permission_sources',
        ] as $timestampedTable) {
            $this->assertTrue(
                Schema::hasColumns($timestampedTable, [
                    'created_at',
                    'updated_at',
                ]),
                "Expected [{$timestampedTable}] to retain model timestamps.",
            );
        }

        $this->assertSame([
            ['name' => 'administrator', 'guard_name' => 'web', 'is_protected' => 1],
            ['name' => 'board-of-directors', 'guard_name' => 'web', 'is_protected' => 0],
            ['name' => 'client-services-advisor', 'guard_name' => 'web', 'is_protected' => 0],
            ['name' => 'human-resources', 'guard_name' => 'web', 'is_protected' => 0],
            ['name' => 'management', 'guard_name' => 'web', 'is_protected' => 0],
            ['name' => 'receptionist', 'guard_name' => 'web', 'is_protected' => 0],
            ['name' => 'service-user', 'guard_name' => 'web', 'is_protected' => 1],
            ['name' => 'staff', 'guard_name' => 'web', 'is_protected' => 1],
            ['name' => 'student', 'guard_name' => 'web', 'is_protected' => 1],
            ['name' => 'super-admin', 'guard_name' => 'web', 'is_protected' => 1],
            ['name' => 'volunteer', 'guard_name' => 'web', 'is_protected' => 1],
        ], DB::table('roles')
            ->orderBy('name')
            ->get(['name', 'guard_name', 'is_protected'])
            ->map(static fn (object $role): array => (array) $role)
            ->all());

        $expectedPermissions = app(AuthorizationProfile::class)->permissions();
        sort($expectedPermissions);
        $this->assertSame(
            $expectedPermissions,
            DB::table('permissions')->orderBy('name')->pluck('name')->all(),
        );
        $this->assertSame(
            count($expectedPermissions),
            DB::table('permissions')
                ->where('guard_name', 'web')
                ->where('is_code_owned', true)
                ->count(),
        );

        $this->assertSame([
            ['group_name' => 'myapesaccount.admin', 'role_name' => 'administrator', 'is_immutable' => 1],
            ['group_name' => 'myapesaccount.staff', 'role_name' => 'staff', 'is_immutable' => 1],
            ['group_name' => 'myapesaccount.student', 'role_name' => 'student', 'is_immutable' => 1],
            ['group_name' => 'myapesaccount.superadmin', 'role_name' => 'super-admin', 'is_immutable' => 1],
            ['group_name' => 'myapesaccount.volunteer', 'role_name' => 'volunteer', 'is_immutable' => 1],
        ], DB::table('directory_group_role_mappings')
            ->join('directory_groups', 'directory_groups.id', '=', 'directory_group_role_mappings.directory_group_id')
            ->join('roles', 'roles.id', '=', 'directory_group_role_mappings.role_id')
            ->orderBy('directory_groups.name')
            ->get([
                'directory_groups.name as group_name',
                'roles.name as role_name',
                'directory_group_role_mappings.is_immutable',
            ])
            ->map(static fn (object $mapping): array => (array) $mapping)
            ->all());

        $this->assertSame('hybrid', User::IDENTITY_HYBRID);
        $this->assertTrue(config('permission.teams'));
        $this->assertTrue(config('permission.enable_wildcard_permission'));
        $this->assertFalse(config('permission.register_permission_check_method'));
        $this->assertFalse(config('permission.display_permission_in_exception'));
        $this->assertFalse(config('permission.display_role_in_exception'));
    }

    private function columnIsNullable(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $definition) {
            if ($definition['name'] === $column) {
                return $definition['nullable'];
            }
        }

        return false;
    }

    public function test_protected_permission_matrix_is_exact(): void
    {
        $actual = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->orderBy('roles.name')
            ->orderBy('permissions.name')
            ->get([
                'roles.name as role_name',
                'permissions.name as permission_name',
            ])
            ->groupBy('role_name')
            ->map(
                static fn ($rows): array => $rows
                    ->pluck('permission_name')
                    ->values()
                    ->all(),
            )
            ->all();

        $expected = app(AuthorizationProfile::class)->permissionMatrix();
        foreach (DefaultJobRoles::names() as $jobRole) {
            $expected[$jobRole] = DefaultJobRoles::defaultPermissions($jobRole);
        }
        ksort($expected);
        foreach ($expected as &$permissions) {
            sort($permissions);
        }
        unset($permissions);

        $this->assertSame($expected, $actual);
    }
}
