<?php

namespace Tests\Feature;

use App\Core\Accounts\DirectoryGroup;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\DirectoryRoleSynchronizer;
use App\Support\DirectoryGroupPrefix;
use App\Support\DirectoryLegacyGroupAliases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Tickets\Models\SupportTicket;
use Tests\TestCase;

class AuthorizationAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function httpAccessMatrix(): array
    {
        return [
            'guest' => ['guest', 'redirect', 'redirect', 'redirect', 'redirect'],
            'public' => ['public', 'own', 'forbidden', 'forbidden', 'forbidden'],
            'volunteer' => ['volunteer', 'all', 'forbidden', 'forbidden', 'forbidden'],
            'student' => ['student', 'all', 'forbidden', 'forbidden', 'forbidden'],
            'staff' => ['staff', 'all', 'deleted', 'forbidden', 'forbidden'],
            'administrator' => ['administrator', 'all', 'deleted', 'ok', 'ok'],
            'administrator via retired myapesaccount.superadmin group' => [
                'superadmin-canonical', 'all', 'deleted', 'ok', 'ok',
            ],
            'administrator via legacy alias mapping' => [
                'superadmin-alias', 'all', 'deleted', 'ok', 'ok',
            ],
        ];
    }

    #[DataProvider('httpAccessMatrix')]
    public function test_http_access_matrix_for_services_delete_admin_and_super_admin(
        string $actorKey,
        string $servicesOutcome,
        string $deleteOutcome,
        string $adminUsersOutcome,
        string $superAdminOutcome,
    ): void {
        $owner = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();
        $foreignTicket = $this->ticketFor($owner, 'Foreign matrix ticket');
        $deletableTicket = $this->ticketFor($owner, 'Deletable matrix ticket');
        $actor = $this->actor($actorKey);

        if ($actor !== null) {
            $this->actingAs($actor);
        }

        $this->assertServicesOutcome($servicesOutcome, $foreignTicket, $actor, $owner);
        $this->assertDeleteOutcome($deleteOutcome, $deletableTicket);
        $this->assertHttpOutcome($this->get('/admin'), $adminUsersOutcome);
        $this->assertHttpOutcome($this->get('/admin/users'), $adminUsersOutcome);
        $this->assertHttpOutcome($this->get('/admin/access'), $superAdminOutcome);
        $this->assertSuperAdminShellOutcome($this->get('/superadmin'), $superAdminOutcome);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool, 3: bool, 4: bool}>
     */
    public static function gatePermissionMatrix(): array
    {
        return [
            'public' => [User::ROLE_SERVICE_USER, false, false, false, false],
            'volunteer' => [User::ROLE_VOLUNTEER, true, false, false, false],
            'student' => [User::ROLE_STUDENT, true, false, false, false],
            'staff' => [User::ROLE_STAFF, true, true, false, false],
            'administrator' => [User::ROLE_ADMIN, true, true, true, true],
        ];
    }

    #[DataProvider('gatePermissionMatrix')]
    public function test_gate_permission_matrix_for_protected_roles(
        string $accessLevel,
        bool $canViewAll,
        bool $canDelete,
        bool $canAdminUsers,
        bool $canSuperAdmin,
    ): void {
        $user = User::factory()->accessLevel($accessLevel)->create();
        $this->actingAs($user);

        $this->assertSame($canViewAll, $user->can('apes-cic.tickets.view-all'));
        $this->assertSame($accessLevel === User::ROLE_SERVICE_USER, $user->can('apes-cic.tickets.view-own'));
        $this->assertSame($accessLevel === User::ROLE_SERVICE_USER, $user->can('apes-cic.tickets.create'));
        $this->assertSame($canDelete, $user->can('apes-cic.tickets.delete'));
        $this->assertSame($canAdminUsers, $user->can('admin.users.view'));
        $this->assertSame($canAdminUsers, $user->can('admin.access'));
        $this->assertFalse($user->can('superadmin.access'));
        $this->assertSame($canSuperAdmin, $user->can('admin.groups.view'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function superAdminAliasProvider(): array
    {
        return [
            'canonical' => ['myapesaccount.admin'],
            'retired superadmin group' => ['myapesaccount.superadmin'],
            'legacy singular' => ['myapes.superadmin'],
            'legacy plural' => ['myapes.superadmins'],
        ];
    }

    #[DataProvider('superAdminAliasProvider')]
    public function test_super_admin_aliases_normalize_to_the_canonical_group_not_a_second_live_group(
        string $incomingGroup,
    ): void {
        $user = User::factory()
            ->accessLevel(User::ROLE_STAFF)
            ->cloudronIdentity('alias-matrix-'.$incomingGroup)
            ->create()
            ->refresh();

        $result = app(DirectoryRoleSynchronizer::class)->synchronize($user, [
            $incomingGroup,
        ]);

        $this->assertTrue($result->eligible);
        $this->assertSame(AuthorizationProfile::ROLE_ADMINISTRATOR, $result->protectedRole);
        $this->assertSame(
            [DirectoryLegacyGroupAliases::canonicalFor($incomingGroup)],
            $user->fresh()->ldap_groups,
        );
        $this->assertDatabaseHas('directory_groups', [
            'name' => 'myapesaccount.admin',
        ]);
        $this->assertDatabaseMissing('directory_groups', [
            'name' => 'myapes.superadmin',
        ]);
        $this->assertDatabaseMissing('directory_groups', [
            'name' => 'myapes.superadmins',
        ]);
        $this->assertSame(
            'myapesaccount.admin',
            DirectoryLegacyGroupAliases::canonicalFor($incomingGroup),
        );
        $this->assertSame(
            ['myapesaccount.admin'],
            DirectoryGroupPrefix::filterGroups([$incomingGroup]),
        );
    }

    public function test_access_groups_catalogue_lists_only_canonical_prefix_groups(): void
    {
        DirectoryGroup::query()->create([
            'name' => 'myapes.superadmins',
            'status' => DirectoryGroup::STATUS_PRESENT,
            'app_enabled' => true,
        ]);
        DirectoryGroup::query()->create([
            'name' => 'intranet.administrator',
            'status' => DirectoryGroup::STATUS_PRESENT,
            'app_enabled' => true,
        ]);

        $superAdmin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();

        $this->actingAs($superAdmin)
            ->get('/admin/access?tab=groups')
            ->assertOk()
            ->assertSee('myapesaccount.staff')
            ->assertSee('myapesaccount.admin')
            ->assertDontSee('myapes.superadmins')
            ->assertDontSee('intranet.administrator');
    }

    public function test_required_directory_groups_are_canonical_myapesaccount_names_only(): void
    {
        $this->assertSame([
            'myapesaccount.staff',
            'myapesaccount.admin',
            'myapesaccount.volunteer',
            'myapesaccount.student',
        ], DirectoryGroupPrefix::requiredGroups());

        foreach (DirectoryGroup::query()->managedMyApesGroups()->pluck('name') as $name) {
            $this->assertTrue(DirectoryGroupPrefix::isManagedGroup($name));
            $this->assertSame($name, DirectoryLegacyGroupAliases::canonicalFor($name));
        }
    }

    public function test_public_dashboard_hides_admin_and_super_admin_navigation(): void
    {
        $public = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();

        $this->actingAs($public)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('href="'.route('admin.index').'"', false)
            ->assertDontSee('href="'.route('superadmin.index').'"', false);
    }

    private function actor(string $key): ?User
    {
        return match ($key) {
            'guest' => null,
            'public' => User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create(),
            'volunteer' => User::factory()->accessLevel(User::ROLE_VOLUNTEER)->create(),
            'student' => User::factory()->accessLevel(User::ROLE_STUDENT)->create(),
            'staff' => User::factory()->accessLevel(User::ROLE_STAFF)->create(),
            'administrator' => User::factory()->accessLevel(User::ROLE_ADMIN)->create(),
            'superadmin-canonical' => $this->directorySuperAdmin(
                'canonical-superadmin',
                ['myapesaccount.superadmin'],
            ),
            'superadmin-alias' => $this->directorySuperAdmin(
                'alias-superadmin',
                ['myapes.superadmins'],
            ),
            default => throw new \InvalidArgumentException($key),
        };
    }

    /**
     * @param  array<int, string>  $groups
     */
    private function directorySuperAdmin(string $subject, array $groups): User
    {
        $user = User::factory()
            ->accessLevel(User::ROLE_STAFF)
            ->cloudronIdentity($subject)
            ->create()
            ->refresh();

        app(DirectoryRoleSynchronizer::class)->synchronize($user, $groups);

        $user = $user->fresh();
        $this->assertSame(
            AuthorizationProfile::ROLE_ADMINISTRATOR,
            app(AuthorizationProfile::class)->effectiveProtectedRole($user),
        );
        $this->assertDatabaseMissing('directory_groups', ['name' => 'myapes.superadmins']);
        $this->assertDatabaseMissing('directory_groups', ['name' => 'myapes.superadmin']);

        return $user;
    }

    private function ticketFor(User $owner, string $subject): SupportTicket
    {
        return SupportTicket::query()->create([
            'user_id' => $owner->id,
            'service_area' => 'it',
            'subject' => $subject,
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Authorization matrix fixture',
        ]);
    }

    private function assertServicesOutcome(
        string $outcome,
        SupportTicket $foreignTicket,
        ?User $actor,
        User $owner,
    ): void {
        $index = $this->get(route('apes-cic.tickets.index'));
        $showForeign = $this->get(route('apes-cic.tickets.show', $foreignTicket));

        if ($outcome === 'redirect') {
            $this->assertHttpOutcome($index, 'redirect');
            $this->assertHttpOutcome($showForeign, 'redirect');

            return;
        }

        $index->assertOk();

        if ($outcome === 'own') {
            $showForeign->assertForbidden();
            $own = $this->ticketFor($actor ?? $owner, 'Own matrix ticket');
            $this->get(route('apes-cic.tickets.show', $own))->assertOk();
            $this->get(route('apes-cic.tickets.index'))
                ->assertOk()
                ->assertSee('Own matrix ticket')
                ->assertDontSee('Foreign matrix ticket');

            return;
        }

        $this->assertSame('all', $outcome);
        $showForeign->assertOk()->assertSee('Foreign matrix ticket');
        $index->assertSee('Foreign matrix ticket');
    }

    private function assertDeleteOutcome(string $outcome, SupportTicket $ticket): void
    {
        $response = $this->delete(route('apes-cic.tickets.destroy', $ticket));

        if ($outcome === 'deleted') {
            $response->assertRedirect(route('apes-cic.tickets.index'));
            $this->assertDatabaseMissing('support_tickets', ['id' => $ticket->id]);

            return;
        }

        $this->assertHttpOutcome($response, $outcome);
        $this->assertDatabaseHas('support_tickets', ['id' => $ticket->id]);
    }

    private function assertHttpOutcome(TestResponse $response, string $outcome): void
    {
        match ($outcome) {
            'redirect' => $response->assertRedirect(route('public.login')),
            'ok' => $response->assertOk(),
            'forbidden' => $response->assertForbidden(),
            default => $this->fail('Unknown HTTP outcome ['.$outcome.'].'),
        };
    }

    private function assertSuperAdminShellOutcome(TestResponse $response, string $outcome): void
    {
        if ($outcome === 'ok') {
            $response->assertRedirect(route('admin.index'));

            return;
        }

        $this->assertHttpOutcome($response, $outcome);
    }
}
