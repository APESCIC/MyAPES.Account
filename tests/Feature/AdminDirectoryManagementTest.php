<?php

namespace Tests\Feature;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\DirectoryGroup;
use App\Core\Accounts\DirectoryGroupRoleMapping;
use App\Core\Accounts\Role;
use App\Core\Accounts\User;
use App\Jobs\RunDirectorySync;
use App\Support\DefaultJobRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminDirectoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_role_mappings_can_be_added_on_managed_groups(): void
    {
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);
        $group = DirectoryGroup::query()
            ->where('name', 'myapesaccount.staff')
            ->firstOrFail();
        $jobRole = Role::query()
            ->where('name', DefaultJobRoles::RECEPTIONIST)
            ->firstOrFail();
        $unmanaged = DirectoryGroup::query()->create([
            'name' => 'myapes.case-reviewers',
            'status' => DirectoryGroup::STATUS_PRESENT,
            'member_count' => 1,
        ]);

        $this->actingAs($superAdmin)
            ->post("/admin/access/groups/{$group->id}/mappings", [
                'role_id' => $jobRole->id,
            ])
            ->assertRedirect('/admin/access?tab=groups');

        $this->assertDatabaseHas('directory_group_role_mappings', [
            'directory_group_id' => $group->id,
            'role_id' => $jobRole->id,
            'is_immutable' => false,
        ]);
        $this->assertDatabaseHas('directory_group_role_mappings', [
            'directory_group_id' => $group->id,
            'role_id' => Role::query()->where('name', 'staff')->value('id'),
            'is_immutable' => true,
        ]);

        $this->actingAs($superAdmin)
            ->from('/admin/access?tab=groups')
            ->post("/admin/access/groups/{$unmanaged->id}/mappings", [
                'role_id' => $jobRole->id,
            ])
            ->assertRedirect('/admin/access?tab=groups')
            ->assertSessionHasErrors('authorization');

        $mapping = DirectoryGroupRoleMapping::query()
            ->where('directory_group_id', $group->id)
            ->where('role_id', $jobRole->id)
            ->firstOrFail();

        $this->actingAs($superAdmin)
            ->delete("/admin/access/mappings/{$mapping->id}")
            ->assertRedirect('/admin/access?tab=groups');

        $this->assertDatabaseMissing('directory_group_role_mappings', [
            'id' => $mapping->id,
        ]);
    }

    public function test_manual_sync_dispatches_existing_job_and_audits_only_safe_context(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);

        $this->actingAs($superAdmin)
            ->post('/admin/access/sync')
            ->assertRedirect('/admin/access?tab=groups');

        Queue::assertPushed(RunDirectorySync::class, 1);
        $audit = AuditLog::query()
            ->where('event', 'authorization.directory_sync_requested')
            ->sole();
        $this->assertSame($superAdmin->id, $audit->user_id);
        $this->assertSame($superAdmin->id, $audit->context['actor_id']);
        $this->assertSame('manual', $audit->context['source_key']);
        $this->assertSame(
            ['actor_id', 'source_key'],
            array_keys($audit->context),
        );
    }

    public function test_production_redis_dispatch_matches_the_default_worker_connection(): void
    {
        Queue::fake();
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
        ]);
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);

        $this->actingAs($superAdmin)
            ->post('/admin/access/sync')
            ->assertRedirect('/admin/access?tab=groups');

        Queue::assertPushed(
            RunDirectorySync::class,
            static fn (RunDirectorySync $job): bool => $job->connection
                === 'redis',
        );
    }

    public function test_manual_sync_uses_a_failover_graph_when_every_child_is_asynchronous(): void
    {
        Queue::fake();
        config([
            'queue.default' => 'failover',
            'queue.connections.failover' => [
                'driver' => 'failover',
                'connections' => ['redis', 'database'],
            ],
        ]);
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);

        $this->actingAs($superAdmin)
            ->post('/admin/access/sync')
            ->assertRedirect('/admin/access?tab=groups');

        Queue::assertPushed(
            RunDirectorySync::class,
            static fn (RunDirectorySync $job): bool => $job->connection
                === 'failover',
        );
    }

    public function test_manual_sync_rejects_a_nested_synchronous_failover_without_dispatch(): void
    {
        Queue::fake();
        config([
            'queue.default' => 'outer-failover',
            'queue.connections.outer-failover' => [
                'driver' => 'failover',
                'connections' => ['database', 'inner-failover'],
            ],
            'queue.connections.inner-failover' => [
                'driver' => 'failover',
                'connections' => ['redis', 'deferred'],
            ],
        ]);
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);

        $this->actingAs($superAdmin)
            ->from('/admin/access?tab=groups')
            ->post('/admin/access/sync')
            ->assertRedirect('/admin/access?tab=groups')
            ->assertSessionHasErrors('authorization');

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'authorization.directory_sync_requested',
        ]);
    }

    /**
     * @param  array<string, mixed>  $queueConfiguration
     */
    #[DataProvider('unsafeQueueGraphs')]
    public function test_manual_sync_fails_closed_for_unsafe_queue_graphs(
        array $queueConfiguration,
    ): void {
        Queue::fake();
        config($queueConfiguration);
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);

        $this->actingAs($superAdmin)
            ->from('/admin/access?tab=groups')
            ->post('/admin/access/sync')
            ->assertRedirect('/admin/access?tab=groups')
            ->assertSessionHasErrors('authorization');

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'authorization.directory_sync_requested',
        ]);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unsafeQueueGraphs(): array
    {
        return [
            'direct sync' => [[
                'queue.default' => 'sync',
            ]],
            'direct deferred' => [[
                'queue.default' => 'deferred',
            ]],
            'direct background' => [[
                'queue.default' => 'background',
            ]],
            'direct null' => [[
                'queue.default' => 'null',
                'queue.connections.null' => ['driver' => 'null'],
            ]],
            'missing connection' => [[
                'queue.default' => 'not-configured',
            ]],
            'missing failover child' => [[
                'queue.default' => 'broken-failover',
                'queue.connections.broken-failover' => [
                    'driver' => 'failover',
                    'connections' => ['database', 'not-configured'],
                ],
            ]],
            'cyclic failover' => [[
                'queue.default' => 'cycle-a',
                'queue.connections.cycle-a' => [
                    'driver' => 'failover',
                    'connections' => ['cycle-b'],
                ],
                'queue.connections.cycle-b' => [
                    'driver' => 'failover',
                    'connections' => ['cycle-a'],
                ],
            ]],
        ];
    }

    public function test_admin_groups_index_lists_managed_prefix_groups_and_hides_historical_noise(): void
    {
        $superAdmin = $this->userWithAccess(User::ROLE_ADMIN);
        DirectoryGroup::query()->create([
            'name' => 'intranet.managers',
            'status' => DirectoryGroup::STATUS_PRESENT,
            'member_count' => 9,
            'app_enabled' => false,
        ]);
        DirectoryGroup::query()->create([
            'name' => 'myapesaccount.ops-review',
            'status' => DirectoryGroup::STATUS_PRESENT,
            'member_count' => 2,
            'app_enabled' => true,
        ]);

        $response = $this->actingAs($superAdmin)
            ->followingRedirects()
            ->get('/admin/groups')
            ->assertOk()
            ->assertSee('myapesaccount.staff')
            ->assertSee('myapesaccount.ops-review')
            ->assertSee('Historical non-prefix catalogue rows')
            ->assertDontSee('intranet.managers');

        $this->assertDatabaseHas('directory_groups', [
            'name' => 'intranet.managers',
            'status' => DirectoryGroup::STATUS_PRESENT,
        ]);
        $this->assertStringNotContainsString(
            'intranet.managers',
            $response->getContent(),
        );
    }

    private function userWithAccess(string $accessLevel): User
    {
        return User::factory()
            ->accessLevel($accessLevel)
            ->create()
            ->refresh();
    }
}
