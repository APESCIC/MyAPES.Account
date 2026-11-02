<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Support\AccessCompatibilityDatabaseGuard;
use Database\Factories\UserFactory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class UserAccessCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (Schema::hasTable('role_sources')) {
            $this->runInMaintenanceMode(
                fn () => $this->phaseBMigration()->down(),
            );
        }
    }

    public function test_access_level_reads_legacy_value_and_dual_writes_role_when_present(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(
            method_exists($user, 'accessLevel') && method_exists($user, 'setAccessLevel'),
            'The centralized User access-level API is missing.',
        );

        $user->setAccessLevel(User::ROLE_ADMIN)->save();

        $this->assertSame(User::ROLE_ADMIN, $user->accessLevel());
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'legacy_access_level' => User::ROLE_ADMIN,
            'role' => User::ROLE_ADMIN,
        ]);

        app(AccessCompatibilityDatabaseGuard::class)->drop();
        DB::table('users')->where('id', $user->id)->update([
            'legacy_access_level' => User::ROLE_STAFF,
            'role' => User::ROLE_ADMIN,
        ]);

        $this->assertSame(User::ROLE_STAFF, $user->fresh()->accessLevel());
    }

    public function test_access_level_rejects_unsupported_values(): void
    {
        $user = User::factory()->make();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported access level [owner].');

        $user->setAccessLevel('owner');
    }

    public function test_access_columns_cannot_bypass_the_centralized_setter_through_mass_assignment(): void
    {
        $user = User::factory()->create();

        $user->fill([
            'legacy_access_level' => User::ROLE_ADMIN,
            'role' => User::ROLE_ADMIN,
        ])->save();

        $user->refresh();
        $this->assertSame(User::ROLE_SERVICE_USER, $user->accessLevel());
        $this->assertSame(User::ROLE_SERVICE_USER, $user->getRawOriginal('role'));
    }

    public function test_access_api_and_scopes_work_after_role_is_removed(): void
    {
        app(AccessCompatibilityDatabaseGuard::class)->drop();
        Schema::table('users', function ($table) {
            $table->dropColumn('role');
        });

        $this->assertTrue(
            method_exists(User::class, 'scopeWithAccessLevels'),
            'The centralized access-level query scope is missing.',
        );

        $admin = User::factory()->make(['email' => 'admin@example.com']);
        $admin->setAccessLevel(User::ROLE_ADMIN)->save();

        $serviceUser = User::factory()->make(['email' => 'service@example.com']);
        $serviceUser->setAccessLevel(User::ROLE_SERVICE_USER)->save();

        $this->assertSame(User::ROLE_ADMIN, $admin->fresh()->accessLevel());
        $this->assertTrue($admin->isStaff());
        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($serviceUser->isStaff());
        $this->assertFalse($serviceUser->isAdmin());
        $this->assertSame(
            [$admin->id],
            User::query()
                ->withAccessLevels([User::ROLE_STAFF, User::ROLE_ADMIN])
                ->pluck('id')
                ->all(),
        );
    }

    public function test_cloudron_identity_requires_the_type_and_a_non_blank_subject(): void
    {
        $this->assertTrue(
            defined(User::class.'::IDENTITY_LOCAL')
                && defined(User::class.'::IDENTITY_CLOUDRON_OIDC')
                && method_exists(User::class, 'isCloudronIdentity'),
            'The centralized identity-type API is missing.',
        );

        $cloudronUser = User::factory()->make([
            'identity_type' => User::IDENTITY_CLOUDRON_OIDC,
            'oidc_sub' => 'cloudron-subject',
        ]);
        $missingSubject = User::factory()->make([
            'identity_type' => User::IDENTITY_CLOUDRON_OIDC,
            'oidc_sub' => '   ',
        ]);
        $localUser = User::factory()->make([
            'identity_type' => User::IDENTITY_LOCAL,
            'oidc_sub' => 'unexpected-subject',
        ]);

        $this->assertTrue($cloudronUser->isCloudronIdentity());
        $this->assertFalse($missingSubject->isCloudronIdentity());
        $this->assertFalse($localUser->isCloudronIdentity());
    }

    public function test_factory_states_create_compatible_access_and_identity_records(): void
    {
        $this->assertTrue(
            method_exists(UserFactory::class, 'accessLevel')
                && method_exists(UserFactory::class, 'cloudronIdentity'),
            'The compatibility-aware User factory states are missing.',
        );

        $user = User::factory()
            ->accessLevel(User::ROLE_STAFF)
            ->cloudronIdentity('factory-subject')
            ->create();

        $this->assertSame(User::ROLE_STAFF, $user->accessLevel());
        $this->assertSame(User::ROLE_STAFF, $user->getRawOriginal('role'));
        $this->assertSame(User::IDENTITY_CLOUDRON_OIDC, $user->identity_type);
        $this->assertSame('factory-subject', $user->oidc_sub);
    }

    private function phaseBMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path(
            'migrations/2026_07_28_000100_cut_over_authorization_domain.php',
        );

        return $migration;
    }
}
