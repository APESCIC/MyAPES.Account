<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AdminPeopleSplitTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_users_leaves_out_every_staff_side_account_and_matches_the_overview(): void
    {
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create(['name' => 'Ada Admin']);
        $people = $this->people();

        $publicList = $this->actingAs($administrator)
            ->get(route('admin.public-accounts.index'))
            ->assertOk()
            ->assertSeeText($people['public']->name);
        $staffList = $this->actingAs($administrator)
            ->get(route('admin.staff-accounts.index'))
            ->assertOk();

        $this->assertSame([$people['public']->id], $this->listedIds($publicList));
        $this->assertEqualsCanonicalizing(
            [$administrator->id, ...collect($people)->except('public')->pluck('id')->all()],
            $this->listedIds($staffList),
        );

        $overview = $this->actingAs($administrator)->get(route('admin.index'))->assertOk();
        $accounts = $overview->viewData('dashboard')['accounts'];

        $this->assertSame(1, $accounts['public_users']);
        $this->assertSame($publicList->viewData('users')->total(), $accounts['public_users']);
        $this->assertSame($staffList->viewData('users')->total(), $accounts['staff_users']);
        $this->assertSame($accounts['total'], $accounts['public_users'] + $accounts['staff_users']);
        $overview->assertSee('data-count="public-users"', false)->assertSeeText('Manage public accounts (1)');
    }

    public function test_staff_users_role_filter_admin_badge_and_all_accounts_link(): void
    {
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create(['name' => 'Ada Admin']);
        $people = $this->people();

        $expected = [
            'admin' => [$administrator->id],
            'staff' => [$people['staff']->id, $people['cloudron']->id, $people['hybrid']->id, $people['pending']->id],
            'volunteer' => [$people['volunteer']->id],
            'student' => [$people['student']->id],
        ];

        foreach ($expected as $type => $ids) {
            $response = $this->actingAs($administrator)
                ->get(route('admin.staff-accounts.index', ['staff_type' => $type]))
                ->assertOk();

            $this->assertEqualsCanonicalizing($ids, $this->listedIds($response), "Role filter [{$type}]");
        }

        $this->actingAs($administrator)
            ->get(route('admin.staff-accounts.index'))
            ->assertOk()
            ->assertSee('id="staff-type"', false)
            ->assertDontSee('id="protected-role"', false)
            ->assertSee('data-badge="admin"', false)
            ->assertSee('href="'.route('admin.users.index').'"', false);

        $this->actingAs($administrator)
            ->get(route('admin.staff-accounts.index', ['staff_type' => 'owner']))
            ->assertSessionHasErrors('staff_type');
    }

    public function test_unmapped_cloudron_accounts_get_a_badge_linking_to_access(): void
    {
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $this->people();

        $content = $this->actingAs($administrator)
            ->get(route('admin.staff-accounts.index'))
            ->assertOk()
            ->assertSee('href="'.route('admin.access.index', ['tab' => 'groups']).'"', false)
            ->assertSeeText('No Cloudron group mapped')
            ->getContent();

        $this->assertSame(3, substr_count($content, 'data-badge="unmapped"'));

        $this->actingAs($administrator)
            ->get(route('admin.staff-accounts.index', ['staff_type' => 'volunteer']))
            ->assertOk()
            ->assertDontSee('data-badge="unmapped"', false);

        $this->actingAs($administrator)
            ->get(route('admin.access.index', ['tab' => 'groups']))
            ->assertOk();
    }

    public function test_staff_list_is_called_staff_users_everywhere(): void
    {
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($administrator)
            ->get(route('admin.staff-accounts.index'))
            ->assertOk()
            ->assertSee('<title>Staff Accounts | MyAPES Account</title>', false)
            ->assertSee('<h1 id="admin-users-title">Staff Accounts</h1>', false)
            ->assertSee('>Staff Accounts</span>', false)
            ->assertSeeInOrder(['aria-label="Breadcrumb"', '>People<', '>Staff Accounts<'], false);

        $search = collect(app(\App\Services\AdminSearchCatalogue::class)->entriesFor($administrator))->firstWhere('id', 'admin.staff');
        $this->assertSame('Staff Accounts', $search['label']);

        $this->assertSame('Staff', __('workspace.panels.staff'));
    }

    public function test_access_lives_under_security_and_public_profiles_are_separate(): void
    {
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $people = $this->people();
        $profileOnly = User::factory()->create([
            'name' => 'Priya Profile',
            'account_state' => User::ACCOUNT_STATE_PROFILE_ONLY,
        ]);

        $this->actingAs($administrator)
            ->get(route('admin.access.index'))
            ->assertOk()
            ->assertSeeInOrder(['aria-label="Breadcrumb"', '>'.__('workspace.groups.security').'<', '>Access<'], false);

        $publicList = $this->actingAs($administrator)
            ->get(route('admin.public-accounts.index'))
            ->assertOk();
        $this->assertContains($people['public']->id, $this->listedIds($publicList));
        $this->assertNotContains($profileOnly->id, $this->listedIds($publicList));

        $profiles = $this->actingAs($administrator)
            ->get(route('admin.public-profiles.index'))
            ->assertOk()
            ->assertSeeInOrder(['aria-label="Breadcrumb"', '>People<', '>'.__('terms.public_profiles').'<'], false);
        $this->assertSame([$profileOnly->id], $this->listedIds($profiles));
    }

    /**
     * @return list<int>
     */
    private function listedIds(TestResponse $response): array
    {
        return $response->viewData('users')->getCollection()->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * @return array<string, User>
     */
    private function people(): array
    {
        return [
            'public' => User::factory()->create(['name' => 'Pat Public']),
            'student' => User::factory()->protectedRole(AuthorizationProfile::ROLE_STUDENT)->create(['name' => 'Sam Student']),
            'volunteer' => User::factory()->protectedRole(AuthorizationProfile::ROLE_VOLUNTEER)->create(['name' => 'Val Volunteer']),
            'staff' => User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create(['name' => 'Stef Staff']),
            'cloudron' => User::factory()->directoryIdentity('cloudron-unmapped')->create(['name' => 'Cleo Cloudron']),
            'hybrid' => User::factory()->create([
                'name' => 'Harper Hybrid',
                'identity_type' => User::IDENTITY_HYBRID,
                'oidc_sub' => 'hybrid-unmapped',
            ]),
            'pending' => User::factory()->create([
                'name' => 'Penny Pending',
                'identity_type' => User::IDENTITY_CLOUDRON_OIDC,
                'oidc_sub' => null,
            ]),
        ];
    }
}
