<?php

namespace Tests\Feature;

use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Core\Auth\DirectoryUserProfile;
use App\Services\AuthorizationProfile;
use App\Services\DirectoryRoleSynchronizer;
use App\Services\DirectoryUserSynchronizer;
use App\Services\LdapUserResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeLdapUserResolver;
use Tests\TestCase;

class DirectoryUserSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    private FakeLdapUserResolver $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = new FakeLdapUserResolver;
        $this->app->instance(LdapUserResolver::class, $this->directory);
    }

    public function test_sync_creates_directory_users_and_rematerializes_roles(): void
    {
        $this->directory->membersByGroup = [
            'myapesaccount.staff' => [
                $this->profile(
                    'staff.sync@example.test',
                    'Sync Staff',
                    ['myapesaccount.staff'],
                ),
            ],
        ];

        $stats = app(DirectoryUserSynchronizer::class)->synchronize();

        $this->assertSame(1, $stats['seen']);
        $this->assertSame(1, $stats['created']);
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(0, $stats['suspended']);

        $user = User::query()
            ->where('email', 'staff.sync@example.test')
            ->firstOrFail();

        $this->assertSame(User::IDENTITY_CLOUDRON_OIDC, $user->identity_type);
        $this->assertSame(
            AuthorizationProfile::ROLE_STAFF,
            app(AuthorizationProfile::class)->effectiveProtectedRole($user),
        );
        $this->assertDatabaseHas('role_sources', [
            'user_id' => $user->id,
            'source' => RoleSource::SOURCE_DIRECTORY,
        ]);
    }

    public function test_sync_does_not_create_or_mutate_public_local_accounts(): void
    {
        $local = User::factory()->create([
            'email' => 'public.local@example.test',
            'identity_type' => User::IDENTITY_LOCAL,
            'name' => 'Public Local',
        ]);

        $this->directory->membersByGroup = [
            'myapesaccount.staff' => [
                $this->profile(
                    'public.local@example.test',
                    'Would Be Staff',
                    ['myapesaccount.staff'],
                ),
            ],
        ];

        $stats = app(DirectoryUserSynchronizer::class)->synchronize();

        $this->assertSame(1, $stats['seen']);
        $this->assertSame(0, $stats['created']);
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(1, User::query()->where('email', 'public.local@example.test')->count());
        $this->assertSame('Public Local', $local->fresh()->name);
        $this->assertSame(User::IDENTITY_LOCAL, $local->fresh()->identity_type);
        $this->assertSame(
            AuthorizationProfile::ROLE_SERVICE_USER,
            app(AuthorizationProfile::class)->effectiveProtectedRole($local->fresh()),
        );
    }

    public function test_sync_suspends_directory_users_missing_from_required_groups(): void
    {
        $user = User::factory()
            ->directoryIdentity('oidc-missing-staff')
            ->create([
                'email' => 'gone.staff@example.test',
                'name' => 'Gone Staff',
            ]);
        app(DirectoryRoleSynchronizer::class)->synchronize(
            $user,
            ['myapesaccount.staff'],
            false,
        );

        $this->directory->membersByGroup = [];

        $stats = app(DirectoryUserSynchronizer::class)->synchronize();

        $this->assertSame(0, $stats['seen']);
        $this->assertSame(1, $stats['suspended']);

        $user->refresh();
        $this->assertNotNull($user->suspended_at);
        $this->assertSame(
            DirectoryUserSynchronizer::SUSPENSION_REASON_DIRECTORY_DISABLED,
            $user->suspension_reason,
        );
        $this->assertFalse(
            app(AuthorizationProfile::class)->hasDirectoryProtectedEligibility($user),
        );
        $this->assertDatabaseMissing('role_sources', [
            'user_id' => $user->id,
            'source' => RoleSource::SOURCE_DIRECTORY,
        ]);
    }

    public function test_sync_unsuspends_directory_disabled_users_when_they_reappear(): void
    {
        $user = User::factory()
            ->directoryIdentity('oidc-returning-staff')
            ->create([
                'email' => 'returning.staff@example.test',
                'name' => 'Returning Staff',
                'suspended_at' => now()->subHour(),
                'suspension_reason' => DirectoryUserSynchronizer::SUSPENSION_REASON_DIRECTORY_DISABLED,
            ]);

        $this->directory->membersByGroup = [
            'myapesaccount.staff' => [
                $this->profile(
                    'returning.staff@example.test',
                    'Returning Staff',
                    ['myapesaccount.staff'],
                ),
            ],
        ];

        $stats = app(DirectoryUserSynchronizer::class)->synchronize();

        $this->assertSame(1, $stats['seen']);
        $this->assertSame(1, $stats['unsuspended']);
        $this->assertSame(0, $stats['suspended']);

        $user->refresh();
        $this->assertNull($user->suspended_at);
        $this->assertNull($user->suspension_reason);
        $this->assertSame(
            AuthorizationProfile::ROLE_STAFF,
            app(AuthorizationProfile::class)->effectiveProtectedRole($user),
        );
    }

    public function test_sync_does_not_clear_admin_suspensions(): void
    {
        $user = User::factory()
            ->directoryIdentity('oidc-admin-suspended')
            ->create([
                'email' => 'admin.suspended@example.test',
                'name' => 'Admin Suspended',
                'suspended_at' => now()->subHour(),
                'suspension_reason' => 'Security review',
            ]);

        $this->directory->membersByGroup = [
            'myapesaccount.staff' => [
                $this->profile(
                    'admin.suspended@example.test',
                    'Admin Suspended',
                    ['myapesaccount.staff'],
                ),
            ],
        ];

        $stats = app(DirectoryUserSynchronizer::class)->synchronize();

        $this->assertSame(1, $stats['updated']);
        $this->assertSame(0, $stats['unsuspended']);

        $user->refresh();
        $this->assertNotNull($user->suspended_at);
        $this->assertSame('Security review', $user->suspension_reason);
    }

    public function test_sync_from_legacy_alias_membership_creates_canonical_directory_users(): void
    {
        $this->directory->membersByGroup = [
            'myapesaccount.admin' => [
                $this->profile(
                    'alias.superadmin@example.test',
                    'Alias Superadmin',
                    ['myapes.superadmins'],
                ),
            ],
        ];

        $stats = app(DirectoryUserSynchronizer::class)->synchronize();

        $this->assertSame(1, $stats['created']);
        $user = User::query()
            ->where('email', 'alias.superadmin@example.test')
            ->firstOrFail();

        $this->assertSame(User::IDENTITY_CLOUDRON_OIDC, $user->identity_type);
        $this->assertNotSame(User::IDENTITY_LOCAL, $user->identity_type);
        $this->assertSame(
            AuthorizationProfile::ROLE_ADMINISTRATOR,
            app(AuthorizationProfile::class)->effectiveProtectedRole($user),
        );
        $this->assertSame(['myapesaccount.admin'], $user->ldap_groups);
        $this->assertDatabaseMissing('directory_groups', [
            'name' => 'myapes.superadmins',
        ]);
        $this->assertDatabaseMissing('users', [
            'email' => 'alias.superadmin@example.test',
            'identity_type' => User::IDENTITY_LOCAL,
        ]);
    }

    public function test_sync_never_creates_identity_type_local_public_users(): void
    {
        $this->directory->membersByGroup = [
            'myapesaccount.staff' => [
                $this->profile(
                    'new.directory@example.test',
                    'New Directory',
                    ['myapesaccount.staff'],
                ),
            ],
        ];

        app(DirectoryUserSynchronizer::class)->synchronize();

        $this->assertSame(
            [User::IDENTITY_CLOUDRON_OIDC],
            User::query()
                ->where('email', 'new.directory@example.test')
                ->pluck('identity_type')
                ->all(),
        );
        $this->assertSame(
            0,
            User::query()
                ->where('identity_type', User::IDENTITY_LOCAL)
                ->where('email', 'new.directory@example.test')
                ->count(),
        );
    }

    public function test_suspended_directory_users_cannot_staff_login_via_oidc_session_gate(): void
    {
        $user = User::factory()
            ->directoryIdentity('oidc-suspended-login')
            ->create([
                'email' => 'suspended.login@example.test',
                'suspended_at' => now(),
                'suspension_reason' => DirectoryUserSynchronizer::SUSPENSION_REASON_DIRECTORY_DISABLED,
            ]);

        $this->actingAs($user);

        $this->get(route('dashboard'))
            ->assertRedirect(route('public.login'));
        $this->assertGuest();
    }

    /**
     * @param  array<int, string>  $groups
     */
    private function profile(string $email, string $name, array $groups): DirectoryUserProfile
    {
        return new DirectoryUserProfile(
            email: $email,
            name: $name,
            jobTitle: 'Coordinator',
            workPhone: null,
            groups: $groups,
        );
    }
}
