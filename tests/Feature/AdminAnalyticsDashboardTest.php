<?php

namespace Tests\Feature;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Services\AuthorizationProfile;
use App\Services\AuthorizationRoleMaterializer;
use App\Services\ModuleInstallationSynchronizer;
use App\Services\ModuleProjectionCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Plugins\Tickets\Models\SupportTicket;
use Tests\TestCase;

class AdminAnalyticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_index_requires_analytics_view(): void
    {
        $staff = User::factory()->accessLevel(User::ROLE_STAFF)->create();
        $admin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();

        $this->actingAs($staff)->get(route('admin.index'))->assertForbidden();

        $superAdminResponse = $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('Admin overview')
            ->assertSee('data-kpi="median-closure"', false)
            ->assertSee('data-kpi="enabled-modules"', false)
            ->assertSee('data-kpi="module-alerts"', false)
            ->assertSee('data-kpi="total-accounts"', false)
            ->assertSee('data-kpi="created-in-range"', false)
            ->assertSee('data-kpi="suspended-accounts"', false)
            ->assertSee('data-kpi="open-workload"', false)
            ->assertSee('data-kpi="high-or-urgent"', false)
            ->assertSee('data-kpi="unassigned"', false)
            ->assertSee('Created versus closed')
            ->assertSee('data-chart-frame="trend"', false)
            ->assertSee('data-chart-frame="workload"', false)
            ->assertSee('id="analytics-trend-chart"', false)
            ->assertSee('id="analytics-workload-chart"', false)
            ->assertSee('data-table="created-versus-closed"', false)
            ->assertSee('data-table="workload-by-service"', false)
            ->assertDontSee('cdn.jsdelivr.net');
        $this->assertKpisAppearOnce($superAdminResponse->getContent(), [
            'total-accounts',
            'created-in-range',
            'suspended-accounts',
            'open-workload',
            'high-or-urgent',
            'unassigned',
            'enabled-modules',
            'median-closure',
            'module-alerts',
        ]);
    }

    public function test_invalid_range_defaults_to_thirty_days_and_boundaries_use_app_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 15:00:00', 'UTC'));
        $admin = User::factory()->accessLevel(User::ROLE_ADMIN)->create([
            'created_at' => Carbon::parse('2026-08-19 10:00:00', 'UTC'),
        ]);
        User::factory()->create([
            'created_at' => Carbon::parse('2026-07-21 23:59:59', 'UTC'),
        ]);
        User::factory()->create([
            'created_at' => Carbon::parse('2026-07-22 00:00:00', 'UTC'),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.index', ['range' => 12]));
        $dashboard = $response->viewData('dashboard');

        $response->assertOk();
        $this->assertSame(30, $response->viewData('range'));
        $this->assertSame(30, $dashboard['range']);
        $this->assertSame('UTC', $dashboard['timezone']);
        $this->assertSame(2, $dashboard['accounts']['created_in_range']);
        $this->assertSame(3, $dashboard['accounts']['total']);
        $this->assertCount(30, $dashboard['workload']['days']);
        $this->assertSame('2026-07-22', $dashboard['workload']['days'][0]);
        $this->assertSame('2026-08-20', $dashboard['workload']['days'][29]);

        $seven = $this->actingAs($admin)->get(route('admin.index', ['range' => 7]));
        $this->assertSame(7, $seven->viewData('range'));
        $this->assertCount(7, $seven->viewData('dashboard')['workload']['days']);

        $ninety = $this->actingAs($admin)->get(route('admin.index', ['range' => 90]));
        $this->assertSame(90, $ninety->viewData('range'));
        $this->assertCount(90, $ninety->viewData('dashboard')['workload']['days']);
    }

    public function test_kpis_match_fixtures_for_current_open_workload_and_median_closure(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'UTC'));
        $admin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();
        $owner = User::factory()->create();
        $this->ticketFor($owner, [
            'subject' => 'Older still-open ticket',
            'priority' => 'urgent',
            'status' => 'open',
            'created_at' => Carbon::parse('2026-01-01 10:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-01-01 10:00:00', 'UTC'),
        ]);
        $this->ticketFor($owner, [
            'assigned_to' => $admin->id,
            'subject' => 'Closed in range',
            'priority' => 'medium',
            'status' => 'closed',
            'closed_at' => Carbon::parse('2026-08-18 12:00:00', 'UTC'),
            'created_at' => Carbon::parse('2026-08-18 10:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-08-18 12:00:00', 'UTC'),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.index', ['range' => 7]));
        $dashboard = $response->viewData('dashboard');
        $instances = collect($dashboard['workload']['by_instance'])->keyBy('key');

        $response->assertOk()
            ->assertSee('data-kpi="open-workload"', false);
        $this->assertSame(1, $dashboard['workload']['open']);
        $this->assertSame(1, $dashboard['workload']['high_or_urgent']);
        $this->assertSame(1, $dashboard['workload']['unassigned']);
        $this->assertSame(120.0, $dashboard['workload']['median_closure_minutes']);
        $this->assertSame(1, $dashboard['workload']['closure_sample_size']);
        $this->assertSame(1, $instances['apes-cic:tickets']['open']);
        $this->assertSame(0, $dashboard['accounts']['suspended']);
        $this->assertGreaterThan(0, $dashboard['accounts']['by_identity_type']['local']);
        $this->assertGreaterThan(0, $dashboard['accounts']['by_access_class']['administrator']);
        $this->assertGreaterThan(0, $dashboard['modules']['enabled']);

        $superAdmin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();
        $this->actingAs($superAdmin)
            ->get(route('admin.index', ['range' => 7]))
            ->assertOk()
            ->assertSee('120.0 minutes')
            ->assertSee('data-kpi="median-closure"', false);
    }

    public function test_open_workload_is_split_across_enabled_sub_cores(): void
    {
        $admin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();
        $owner = User::factory()->create();
        $this->ticketFor($owner, [
            'sub_core_key' => SupportTicket::SUB_CORE_APES_CIC,
            'subject' => 'APES CIC open ticket',
        ]);
        $this->ticketFor($owner, [
            'sub_core_key' => 'pet-care-clinic',
            'service_area' => 'appointment',
            'subject' => 'Clinic open ticket',
        ]);

        $dashboard = $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->viewData('dashboard');
        $instances = collect($dashboard['workload']['by_instance'])->keyBy('key');

        $this->assertSame(1, $instances['apes-cic:tickets']['open']);
        $this->assertSame(1, $instances['pet-care-clinic:tickets']['open']);
        $this->assertSame(2, $dashboard['workload']['open']);
    }

    public function test_disabled_modules_are_visible_on_the_dashboard_without_changing_healthz(): void
    {
        $superAdmin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();
        ModuleInstallation::query()
            ->where('sub_core_key', 'apes-cic')
            ->where('module_key', 'tickets')
            ->update([
                'enabled' => false,
                'disabled_at' => now(),
            ]);

        $response = $this->actingAs($superAdmin)->get(route('admin.index'));
        $dashboard = $response->viewData('dashboard');
        $kinds = collect($dashboard['module_alerts'])->pluck('kind')->all();

        $response->assertOk()
            ->assertSee('data-maintenance-state="inactive"', false)
            ->assertSee('Disabled');
        $this->assertContains('disabled', $kinds);
        $this->get('/healthz')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_superadmin_module_alerts_use_incompatible_not_blocked(): void
    {
        $superAdmin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();

        $response = $this->actingAs($superAdmin)->get(route('admin.index'));
        $dashboard = $response->viewData('dashboard');
        $kinds = collect($dashboard['module_alerts'])->pluck('kind')->unique()->values()->all();

        $response->assertOk()
            ->assertSee('Incompatible')
            ->assertDontSee('>Blocked<', false)
            ->assertSee('data-alert-kind="incompatible"', false)
            ->assertDontSee('data-alert-kind="blocked"', false);

        $this->assertContains('incompatible', $kinds);
        $this->assertNotContains('blocked', $kinds);

        $this->actingAs($superAdmin)
            ->get(route('admin.modules.index'))
            ->assertOk()
            ->assertSee('Incompatible');
    }

    public function test_aggregate_cache_expires_and_does_not_cache_recent_account_identities(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'UTC'));
        $admin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();

        $first = $this->actingAs($admin)->get(route('admin.index'));
        $firstTotal = $first->viewData('dashboard')['accounts']['total'];
        $late = User::factory()->create([
            'name' => 'Live identity stays uncached',
            'email' => 'late-identity@example.test',
        ]);
        $cached = $this->actingAs($admin)->get(route('admin.index'));

        $this->assertSame($firstTotal, $cached->viewData('dashboard')['accounts']['total']);
        $this->assertArrayNotHasKey('recent_users', $cached->viewData('dashboard'));
        $cached->assertSee($late->email);

        Carbon::setTestNow(Carbon::parse('2026-08-20 12:01:01', 'UTC'));
        $fresh = $this->actingAs($admin)->get(route('admin.index'));

        $this->assertSame($firstTotal + 1, $fresh->viewData('dashboard')['accounts']['total']);
        $fresh->assertSee('Recent accounts')
            ->assertSee($late->email);
    }

    public function test_module_projection_invalidation_busts_the_analytics_cache(): void
    {
        $admin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();
        $first = $this->actingAs($admin)->get(route('admin.index'));
        $enabled = $first->viewData('dashboard')['modules']['enabled'];

        ModuleInstallation::query()
            ->where('sub_core_key', 'apes-cic')
            ->where('module_key', 'tickets')
            ->update([
                'enabled' => false,
                'disabled_at' => now(),
            ]);
        $cached = $this->actingAs($admin)->get(route('admin.index'));
        $this->assertSame($enabled, $cached->viewData('dashboard')['modules']['enabled']);

        app(ModuleProjectionCache::class)->invalidate();
        $fresh = $this->actingAs($admin)->get(route('admin.index'));
        $this->assertSame($enabled - 1, $fresh->viewData('dashboard')['modules']['enabled']);
    }

    public function test_admin_access_without_analytics_view_cannot_open_the_dashboard(): void
    {
        $actor = User::factory()->accessLevel(User::ROLE_STAFF)->create();
        $role = Role::query()->create([
            'name' => 'overview-without-analytics',
            'guard_name' => 'web',
        ]);
        $permission = Permission::query()
            ->where('name', AuthorizationProfile::PERMISSION_ADMIN_ACCESS)
            ->where('guard_name', 'web')
            ->firstOrFail();
        $role->permissions()->attach($permission->id);
        app(AuthorizationRoleMaterializer::class)->grant(
            $actor,
            $role,
            RoleSource::SOURCE_LOCAL,
            actor: $actor,
        );

        $this->actingAs($actor)->get(route('admin.index'))->assertForbidden();
    }

    public function test_privileged_events_show_actor_action_and_time_only(): void
    {
        $superAdmin = User::factory()->accessLevel(User::ROLE_ADMIN)->create([
            'name' => 'Dashboard Operator',
        ]);
        AuditLog::query()->create([
            'user_id' => $superAdmin->id,
            'event' => 'authorization.role_updated',
            'auditable_type' => 'role',
            'auditable_id' => 1,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'context' => ['permission' => 'admin.roles.manage'],
        ]);

        $this->actingAs($superAdmin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('authorization.role_updated')
            ->assertSee('Dashboard Operator')
            ->assertDontSee('staff league')
            ->assertDontSee('PHPUnit')
            ->assertDontSee('admin.roles.manage');
    }

    /**
     * @param  list<string>  $kpiKeys
     */
    private function assertKpisAppearOnce(string $html, array $kpiKeys): void
    {
        foreach ($kpiKeys as $kpiKey) {
            $this->assertSame(
                1,
                substr_count($html, 'data-kpi="'.$kpiKey.'"'),
                "Expected data-kpi=\"{$kpiKey}\" exactly once on the page.",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ticketFor(User $owner, array $attributes = []): SupportTicket
    {
        $timestamps = array_intersect_key(
            $attributes,
            array_flip(['created_at', 'updated_at']),
        );
        $ticket = SupportTicket::query()->create(array_merge([
            'sub_core_key' => SupportTicket::SUB_CORE_APES_CIC,
            'user_id' => $owner->id,
            'service_area' => 'operations',
            'subject' => 'General APES CIC ticket',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Dashboard analytics fixture.',
        ], $attributes));

        if ($timestamps !== []) {
            $ticket->timestamps = false;
            $ticket->forceFill($timestamps)->saveQuietly();
            $ticket->timestamps = true;
        }

        return $ticket->fresh();
    }
}
