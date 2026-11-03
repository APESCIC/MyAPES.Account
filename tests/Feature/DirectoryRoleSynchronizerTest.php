<?php

namespace Tests\Feature;

use App\Core\Accounts\DirectoryGroup;
use App\Core\Accounts\DirectoryGroupRoleMapping;
use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Services\AuthorizationRoleMaterializer;
use App\Services\DirectoryRoleSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DirectoryRoleSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_highest_protected_mapping_wins_and_custom_provenance_is_preserved(): void
    {
        $user = User::factory()
            ->accessLevel(User::ROLE_STAFF)
            ->cloudronIdentity('mapping-subject')
            ->create()
            ->refresh();
        [$customRole, $customGroup] = $this->customMapping();
        app(AuthorizationRoleMaterializer::class)->grant(
            $user,
            $customRole,
            RoleSource::SOURCE_LOCAL,
            actor: $user,
        );

        $result = app(DirectoryRoleSynchronizer::class)->synchronize($user, [
            'myapesaccount.staff',
            'myapesaccount.admin',
            $customGroup->name,
        ]);

        $this->assertTrue($result->eligible);
        $this->assertSame('administrator', $result->protectedRole);
        $this->assertSame(
            ['administrator', 'custom-reviewer'],
            $user->fresh()->roles()->orderBy('name')->pluck('name')->all(),
        );
        $this->assertDatabaseHas('role_sources', [
            'user_id' => $user->id,
            'role_id' => $customRole->id,
            'source' => RoleSource::SOURCE_LOCAL,
            'directory_group_id' => null,
        ]);
        $this->assertDatabaseMissing('role_sources', [
            'user_id' => $user->id,
            'role_id' => $customRole->id,
            'source' => RoleSource::SOURCE_DIRECTORY,
        ]);
        $this->assertDatabaseMissing('role_sources', [
            'user_id' => $user->id,
            'source' => RoleSource::SOURCE_DIRECTORY,
            'directory_group_id' => DirectoryGroup::query()
                ->where('name', 'myapesaccount.staff')
                ->value('id'),
        ]);
    }

    public function test_plural_superadmins_group_grants_super_admin(): void
    {
        $user = User::factory()
            ->accessLevel(User::ROLE_STAFF)
            ->cloudronIdentity('plural-superadmins-subject')
            ->create()
            ->refresh();

        $result = app(DirectoryRoleSynchronizer::class)->synchronize($user, [
            'myapesaccount.staff',
            'myapesaccount.admin',
        ]);

        $this->assertTrue($result->eligible);
        $this->assertSame('administrator', $result->protectedRole);
        $this->assertSame(
            ['administrator'],
            $user->fresh()->roles()->orderBy('name')->pluck('name')->all(),
        );
        $this->assertDatabaseHas('role_sources', [
            'user_id' => $user->id,
            'source' => RoleSource::SOURCE_DIRECTORY,
            'directory_group_id' => DirectoryGroup::query()
                ->where('name', 'myapesaccount.admin')
                ->value('id'),
        ]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function legacySuperAdminAliasProvider(): array
    {
        return [
            'myapes.superadmin' => ['myapes.superadmin'],
            'myapes.superadmins' => ['myapes.superadmins'],
        ];
    }

    #[DataProvider('legacySuperAdminAliasProvider')]
    public function test_legacy_superadmin_aliases_grant_the_canonical_group_mapping(
        string $alias,
    ): void {
        $user = User::factory()
            ->accessLevel(User::ROLE_STAFF)
            ->cloudronIdentity('legacy-alias-'.$alias)
            ->create()
            ->refresh();

        $result = app(DirectoryRoleSynchronizer::class)->synchronize($user, [
            $alias,
        ]);

        $this->assertTrue($result->eligible);
        $this->assertSame('administrator', $result->protectedRole);
        $this->assertSame(['myapesaccount.admin'], $user->fresh()->ldap_groups);
        $this->assertDatabaseHas('role_sources', [
            'user_id' => $user->id,
            'source' => RoleSource::SOURCE_DIRECTORY,
            'directory_group_id' => DirectoryGroup::query()
                ->where('name', 'myapesaccount.admin')
                ->value('id'),
        ]);
        $this->assertDatabaseMissing('directory_groups', ['name' => $alias]);
        $this->assertSame(
            1,
            DirectoryGroup::query()->where('name', 'myapesaccount.admin')->count(),
        );
    }

    public function test_loss_of_protected_eligibility_removes_directory_sources_only(): void
    {
        $user = User::factory()
            ->accessLevel(User::ROLE_STAFF)
            ->cloudronIdentity('revocation-subject')
            ->create()
            ->refresh();
        [$customRole, $customGroup] = $this->customMapping();
        $roles = app(AuthorizationRoleMaterializer::class);
        $roles->grant(
            $user,
            $customRole,
            RoleSource::SOURCE_LOCAL,
            actor: $user,
        );
        app(DirectoryRoleSynchronizer::class)->synchronize($user, [
            'myapesaccount.staff',
            $customGroup->name,
        ]);
        $epoch = $user->fresh()->authorization_epoch;

        $result = app(DirectoryRoleSynchronizer::class)->synchronize(
            $user->fresh(),
            [$customGroup->name],
        );

        $this->assertFalse($result->eligible);
        $user->refresh();
        $this->assertSame(
            ['custom-reviewer', 'service-user'],
            $user->roles()->orderBy('name')->pluck('name')->all(),
        );
        $this->assertSame($epoch + 1, $user->authorization_epoch);
        $this->assertSame('service_user', $user->legacy_access_level);
        $this->assertDatabaseMissing('role_sources', [
            'user_id' => $user->id,
            'source' => RoleSource::SOURCE_DIRECTORY,
        ]);
        $this->assertDatabaseHas('role_sources', [
            'user_id' => $user->id,
            'role_id' => $customRole->id,
            'source' => RoleSource::SOURCE_LOCAL,
        ]);
    }

    public function test_directory_loss_rotates_session_state_when_local_sources_keep_the_same_roles(): void
    {
        $user = User::factory()
            ->accessLevel(User::ROLE_SERVICE_USER)
            ->cloudronIdentity('overlapping-revocation-subject')
            ->create()
            ->refresh();
        $materializer = app(AuthorizationRoleMaterializer::class);
        $staffRole = Role::query()->where('name', 'staff')->firstOrFail();
        $serviceUserRole = Role::query()
            ->where('name', 'service-user')
            ->firstOrFail();
        $materializer->grant(
            $user,
            $staffRole,
            RoleSource::SOURCE_SYSTEM,
        );
        $materializer->grant(
            $user,
            $serviceUserRole,
            RoleSource::SOURCE_SYSTEM,
        );

        $synchronizer = app(DirectoryRoleSynchronizer::class);
        $synchronizer->synchronize($user, ['myapesaccount.staff']);
        $user->refresh()->forceFill([
            'remember_token' => 'remember-before-directory-loss',
        ])->save();
        $epoch = $user->authorization_epoch;

        $result = $synchronizer->synchronize($user->fresh(), []);

        $this->assertFalse($result->eligible);
        $this->assertTrue($result->authorizationChanged);
        $user->refresh();
        $this->assertSame($epoch + 1, $user->authorization_epoch);
        $this->assertNotSame(
            'remember-before-directory-loss',
            $user->getRememberToken(),
        );
        $this->assertSame(
            ['service-user', 'staff'],
            $user->roles()->orderBy('name')->pluck('name')->all(),
        );
        $this->assertDatabaseMissing('role_sources', [
            'user_id' => $user->id,
            'source' => RoleSource::SOURCE_DIRECTORY,
        ]);
    }

    public function test_activation_can_defer_role_change_invalidation_to_its_single_cutover_rotation(): void
    {
        $user = User::factory()
            ->accessLevel(User::ROLE_STAFF)
            ->cloudronIdentity('activation-subject')
            ->create()
            ->refresh();
        $user->forceFill([
            'remember_token' => 'remember-before-activation',
        ])->save();
        $user = User::query()->findOrFail($user->id);
        $epoch = $user->authorization_epoch;

        $result = app(DirectoryRoleSynchronizer::class)->synchronize(
            $user,
            ['myapesaccount.admin'],
            false,
        );

        $this->assertTrue($result->authorizationChanged);
        $user->refresh();
        $this->assertSame($epoch, $user->authorization_epoch);
        $this->assertSame(
            'remember-before-activation',
            $user->getRememberToken(),
        );
        $this->assertSame(
            ['administrator'],
            $user->roles()->pluck('name')->all(),
        );
    }

    public function test_non_managed_prefix_groups_are_ignored_during_role_sync(): void
    {
        $user = User::factory()
            ->accessLevel(User::ROLE_SERVICE_USER)
            ->cloudronIdentity('custom-group-subject')
            ->create()
            ->refresh();
        [$customRole, $customGroup] = $this->customMapping();

        $synchronizer = app(DirectoryRoleSynchronizer::class);
        $result = $synchronizer->synchronize($user, [
            $customGroup->name,
            'myapesaccount.staff',
        ]);

        $this->assertTrue($result->eligible);
        $this->assertSame('staff', $result->protectedRole);
        $this->assertTrue(
            $user->fresh()->roles()->where('name', 'staff')->exists(),
        );
        $this->assertFalse(
            $user->fresh()->roles()->where('name', 'custom-reviewer')->exists(),
        );
        $this->assertDatabaseMissing('role_sources', [
            'user_id' => $user->id,
            'role_id' => $customRole->id,
            'source' => RoleSource::SOURCE_DIRECTORY,
        ]);
    }

    /**
     * @return array{Role, DirectoryGroup}
     */
    private function customMapping(): array
    {
        $role = Role::query()->create([
            'name' => 'custom-reviewer',
            'guard_name' => 'web',
        ]);
        $group = DirectoryGroup::query()->create([
            'name' => 'myapes.custom-reviewer',
            'status' => DirectoryGroup::STATUS_PRESENT,
            'app_enabled' => true,
        ]);
        (new DirectoryGroupRoleMapping)->forceFill([
            'directory_group_id' => $group->id,
            'role_id' => $role->id,
            'is_immutable' => false,
        ])->save();

        return [$role, $group];
    }
}
