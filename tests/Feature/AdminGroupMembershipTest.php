<?php

namespace Tests\Feature;

use App\Core\Accounts\DirectoryGroup;
use App\Core\Accounts\User;
use App\Core\Auth\DirectoryUserProfile;
use App\Exceptions\DirectoryUnavailable;
use App\Jobs\RunDirectorySync;
use App\Services\LdapUserResolver;
use App\Support\UkDateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeLdapUserResolver;
use Tests\TestCase;

class AdminGroupMembershipTest extends TestCase
{
    use RefreshDatabase;

    private FakeLdapUserResolver $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = new FakeLdapUserResolver;
        $this->app->instance(LdapUserResolver::class, $this->directory);
    }

    public function test_groups_tab_shows_clickable_member_counts_and_last_sync_as_of(): void
    {
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);
        $syncedAt = now()->subMinutes(12)->seconds(0);
        $group = DirectoryGroup::query()
            ->where('name', 'myapesaccount.staff')
            ->firstOrFail();
        $group->forceFill([
            'member_count' => 3,
            'last_synced_at' => $syncedAt,
        ])->save();

        $asOf = app(UkDateTime::class)->format($syncedAt);

        $this->actingAs($superAdmin)
            ->get('/admin/access?tab=groups')
            ->assertOk()
            ->assertSee('as of '.$asOf, false)
            ->assertSee(
                'href="'.route('admin.groups.show', $group).'"',
                false,
            )
            ->assertSee('>3</a>', false);
    }

    public function test_membership_view_lists_live_directory_members_without_sync(): void
    {
        Queue::fake();
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);
        $group = DirectoryGroup::query()
            ->where('name', 'myapesaccount.staff')
            ->firstOrFail();
        $group->forceFill(['member_count' => 2])->save();

        $this->directory->membersByGroup = [
            'myapesaccount.staff' => [
                new DirectoryUserProfile(
                    email: 'alex.staff@example.test',
                    name: 'Alex Staff',
                    jobTitle: 'Coordinator',
                    workPhone: null,
                    groups: ['myapesaccount.staff'],
                ),
                new DirectoryUserProfile(
                    email: 'blair.staff@example.test',
                    name: 'Blair Staff',
                    jobTitle: null,
                    workPhone: null,
                    groups: ['myapesaccount.staff'],
                ),
            ],
        ];

        $this->actingAs($superAdmin)
            ->get(route('admin.groups.show', $group))
            ->assertOk()
            ->assertSee('Members of')
            ->assertSee('myapesaccount.staff')
            ->assertSee('Catalogue member count')
            ->assertSee('2')
            ->assertSee('Live directory members')
            ->assertSee('Alex Staff')
            ->assertSee('alex.staff@example.test')
            ->assertSee('Blair Staff')
            ->assertSee('blair.staff@example.test')
            ->assertSee('This page does not start a directory sync.')
            ->assertDontSee('Sync from Cloudron');

        Queue::assertNothingPushed();
        Queue::assertNotPushed(RunDirectorySync::class);
    }

    public function test_membership_view_fails_closed_when_directory_is_unavailable(): void
    {
        Queue::fake();
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);
        $group = DirectoryGroup::query()
            ->where('name', 'myapesaccount.volunteer')
            ->firstOrFail();
        $group->forceFill(['member_count' => 1])->save();

        $this->directory->failure = new DirectoryUnavailable(
            'sensitive bind secret must not leak',
        );
        $this->directory->membersByGroup = [
            'myapesaccount.volunteer' => [
                new DirectoryUserProfile(
                    email: 'hidden.volunteer@example.test',
                    name: 'Hidden Volunteer',
                    jobTitle: null,
                    workPhone: null,
                    groups: ['myapesaccount.volunteer'],
                ),
            ],
        ];

        $this->actingAs($superAdmin)
            ->get(route('admin.groups.show', $group))
            ->assertOk()
            ->assertSee('Directory membership could not be loaded')
            ->assertSee('Unavailable')
            ->assertDontSee('hidden.volunteer@example.test')
            ->assertDontSee('Hidden Volunteer')
            ->assertDontSee('sensitive bind secret');

        Queue::assertNothingPushed();
    }

    public function test_membership_view_requires_groups_view_permission(): void
    {
        $staff = $this->userWithAccess(User::ROLE_STAFF);
        $group = DirectoryGroup::query()
            ->where('name', 'myapesaccount.staff')
            ->firstOrFail();

        $this->directory->membersByGroup = [
            'myapesaccount.staff' => [
                new DirectoryUserProfile(
                    email: 'should.not.leak@example.test',
                    name: 'Should Not Leak',
                    jobTitle: null,
                    workPhone: null,
                    groups: ['myapesaccount.staff'],
                ),
            ],
        ];

        $this->actingAs($staff)
            ->get(route('admin.groups.show', $group))
            ->assertForbidden()
            ->assertDontSee('should.not.leak@example.test');
    }

    public function test_unmanaged_directory_groups_are_not_exposed(): void
    {
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);
        $unmanaged = DirectoryGroup::query()->create([
            'name' => 'myapes.case-reviewers',
            'status' => DirectoryGroup::STATUS_PRESENT,
            'member_count' => 4,
        ]);

        $this->actingAs($superAdmin)
            ->get(route('admin.groups.show', $unmanaged))
            ->assertNotFound();
    }

    private function userWithAccess(string $accessLevel): User
    {
        return User::factory()
            ->accessLevel($accessLevel)
            ->create()
            ->refresh();
    }
}
