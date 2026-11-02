<?php

namespace Tests\Feature;

use App\Contracts\MaintenanceModeGateway;
use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Maintenance\MaintenanceWindow;
use App\Services\AuthorizationProfile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeMaintenanceModeGateway;
use Tests\TestCase;

class AdminMaintenanceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_open_the_guarded_maintenance_console(): void
    {
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();

        $this->actingAs($administrator)
            ->get('/admin/maintenance')
            ->assertOk()
            ->assertSeeText('Maintenance')
            ->assertSeeText('Application is available')
            ->assertSeeText('Queue processing pauses');
    }

    public function test_guest_staff_and_super_admin_observe_the_exact_access_boundary(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $superAdministrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();

        $this->get('/admin/maintenance')
            ->assertRedirect('/login');

        $this->actingAs($staff)
            ->get('/admin/maintenance')
            ->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'authorization.admin_denied',
            'user_id' => $staff->id,
        ]);

        $this->actingAs($superAdministrator)
            ->get('/admin/maintenance')
            ->assertOk()
            ->assertSeeText('Maintenance');
    }

    public function test_authorization_happens_before_a_transition_is_attempted(): void
    {
        $maintenanceMode = new FakeMaintenanceModeGateway;
        $this->app->instance(MaintenanceModeGateway::class, $maintenanceMode);
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($staff)
            ->post('/admin/maintenance/activate', [
                'message' => 'Unauthorized transition',
                'confirm_activation' => '1',
            ])
            ->assertForbidden();

        $this->assertFalse($maintenanceMode->active);
        $this->assertDatabaseCount('maintenance_windows', 0);
    }

    public function test_transitions_require_csrf_tokens(): void
    {
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $this->app->instance('env', 'production');

        foreach (['activate', 'deactivate'] as $transition) {
            $this->withMiddleware(ValidateCsrfToken::class)
                ->actingAs($administrator)
                ->post("/admin/maintenance/{$transition}")
                ->assertStatus(419);
        }
    }

    public function test_activation_validates_message_future_end_and_explicit_confirmation(): void
    {
        $maintenanceMode = new FakeMaintenanceModeGateway;
        $this->app->instance(MaintenanceModeGateway::class, $maintenanceMode);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();

        $this->actingAs($administrator)
            ->from('/admin/maintenance')
            ->post('/admin/maintenance/activate', [
                'message' => str_repeat('x', 501),
                'planned_end_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect('/admin/maintenance')
            ->assertSessionHasErrors([
                'message',
                'planned_end_at',
                'confirm_activation',
            ]);

        $this->assertFalse($maintenanceMode->active);
        $this->assertDatabaseCount('maintenance_windows', 0);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'maintenance.activation_validation_failed',
            'user_id' => $administrator->id,
        ]);
    }

    public function test_deactivation_requires_explicit_confirmation(): void
    {
        $maintenanceMode = new FakeMaintenanceModeGateway;
        $maintenanceMode->active = true;
        $this->app->instance(MaintenanceModeGateway::class, $maintenanceMode);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();

        $this->actingAs($administrator)
            ->from('/admin/maintenance')
            ->post('/admin/maintenance/deactivate')
            ->assertRedirect('/admin/maintenance')
            ->assertSessionHasErrors('confirm_deactivation');

        $this->assertTrue($maintenanceMode->active);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'maintenance.deactivation_validation_failed',
            'user_id' => $administrator->id,
        ]);
    }

    public function test_confirmed_activation_uses_native_maintenance_and_records_the_window(): void
    {
        $maintenanceMode = new FakeMaintenanceModeGateway;
        $this->app->instance(MaintenanceModeGateway::class, $maintenanceMode);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();

        $this->actingAs($administrator)
            ->post('/admin/maintenance/activate', [
                'message' => 'Planned account maintenance',
                'planned_end_at' => now()->addHour()->format('Y-m-d H:i:s'),
                'confirm_activation' => '1',
            ])
            ->assertRedirect('/admin/maintenance')
            ->assertSessionHas('status');

        $window = MaintenanceWindow::query()->sole();
        $this->assertSame(MaintenanceWindow::STATE_ACTIVE, $window->state);
        $this->assertSame(MaintenanceWindow::ACTIVE_GUARD, $window->active_guard);
        $this->assertSame($administrator->id, $window->initiated_by);
        $this->assertTrue($maintenanceMode->active);
        $this->assertSame(503, $maintenanceMode->payload['status']);
        $this->assertSame(60, $maintenanceMode->payload['retry']);
        $this->assertSame(60, $maintenanceMode->payload['refresh']);
        $this->assertArrayNotHasKey('secret', $maintenanceMode->payload);
        $this->assertStringContainsString('Planned account maintenance', $maintenanceMode->payload['template']);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'maintenance.activation_succeeded',
            'user_id' => $administrator->id,
            'auditable_type' => 'maintenance_window',
            'auditable_id' => $window->id,
        ]);
        $this->assertSame(
            ['action', 'reason_code', 'state', 'window_id'],
            array_keys(AuditLog::query()->where('event', 'maintenance.activation_succeeded')->sole()->context),
        );
    }

    public function test_confirmed_deactivation_brings_the_application_up_before_closing_history(): void
    {
        $maintenanceMode = new FakeMaintenanceModeGateway;
        $maintenanceMode->active = true;
        $this->app->instance(MaintenanceModeGateway::class, $maintenanceMode);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $window = MaintenanceWindow::query()->create([
            'message' => 'Current maintenance',
            'state' => MaintenanceWindow::STATE_ACTIVE,
            'active_guard' => MaintenanceWindow::ACTIVE_GUARD,
            'initiated_by' => $administrator->id,
            'activated_at' => now()->subMinute(),
        ]);

        $this->actingAs($administrator)
            ->post('/admin/maintenance/deactivate', [
                'confirm_deactivation' => '1',
            ])
            ->assertRedirect('/admin/maintenance')
            ->assertSessionHas('status');

        $window->refresh();
        $this->assertFalse($maintenanceMode->active);
        $this->assertSame(MaintenanceWindow::STATE_ENDED, $window->state);
        $this->assertNull($window->active_guard);
        $this->assertSame($administrator->id, $window->deactivation_requested_by);
        $this->assertSame($administrator->id, $window->ended_by);
        $this->assertNotNull($window->deactivated_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'maintenance.deactivation_succeeded',
            'user_id' => $administrator->id,
            'auditable_id' => $window->id,
        ]);
    }

    public function test_native_activation_failure_is_bounded_sanitized_and_recoverable(): void
    {
        $maintenanceMode = new FakeMaintenanceModeGateway;
        $maintenanceMode->failActivation = true;
        $this->app->instance(MaintenanceModeGateway::class, $maintenanceMode);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();

        $this->actingAs($administrator)
            ->from('/admin/maintenance')
            ->post('/admin/maintenance/activate', [
                'message' => 'Failure boundary test',
                'confirm_activation' => '1',
            ])
            ->assertRedirect('/admin/maintenance')
            ->assertSessionHasErrors('maintenance');

        $window = MaintenanceWindow::query()->sole();
        $this->assertFalse($maintenanceMode->active);
        $this->assertSame(MaintenanceWindow::STATE_ACTIVATION_FAILED, $window->state);
        $this->assertNull($window->active_guard);
        $this->assertSame('native_activation_failed', $window->failure_code);
        $this->assertLessThanOrEqual(255, strlen($window->failure_summary));
        $this->assertStringNotContainsString(
            'secret',
            json_encode([
                $window->failure_summary,
                AuditLog::query()->where('event', 'maintenance.activation_failed')->sole()->context,
            ], JSON_THROW_ON_ERROR),
        );
    }

    public function test_native_deactivation_failure_retains_current_history_and_request_actor(): void
    {
        $maintenanceMode = new FakeMaintenanceModeGateway;
        $maintenanceMode->active = true;
        $maintenanceMode->failDeactivation = true;
        $this->app->instance(MaintenanceModeGateway::class, $maintenanceMode);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $window = MaintenanceWindow::query()->create([
            'message' => 'Failure boundary test',
            'state' => MaintenanceWindow::STATE_ACTIVE,
            'active_guard' => MaintenanceWindow::ACTIVE_GUARD,
            'initiated_by' => $administrator->id,
            'activated_at' => now(),
        ]);

        $this->actingAs($administrator)
            ->from('/admin/maintenance')
            ->post('/admin/maintenance/deactivate', [
                'confirm_deactivation' => '1',
            ])
            ->assertRedirect('/admin/maintenance')
            ->assertSessionHasErrors('maintenance');

        $window->refresh();
        $this->assertTrue($maintenanceMode->active);
        $this->assertSame(MaintenanceWindow::STATE_ACTIVE, $window->state);
        $this->assertSame(MaintenanceWindow::ACTIVE_GUARD, $window->active_guard);
        $this->assertSame($administrator->id, $window->deactivation_requested_by);
        $this->assertSame('native_deactivation_failed', $window->failure_code);
        $this->assertLessThanOrEqual(255, strlen($window->failure_summary));
        $this->assertStringNotContainsString(
            'secret',
            json_encode([
                $window->failure_summary,
                AuditLog::query()->where('event', 'maintenance.deactivation_failed')->sole()->context,
            ], JSON_THROW_ON_ERROR),
        );
    }

    public function test_database_enforces_one_current_window_and_retains_history_after_actor_deletion(): void
    {
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $window = MaintenanceWindow::query()->create([
            'message' => 'Current maintenance',
            'state' => MaintenanceWindow::STATE_ACTIVE,
            'active_guard' => MaintenanceWindow::ACTIVE_GUARD,
            'initiated_by' => $administrator->id,
            'deactivation_requested_by' => $administrator->id,
            'ended_by' => $administrator->id,
        ]);

        try {
            MaintenanceWindow::query()->create([
                'message' => 'Duplicate current maintenance',
                'state' => MaintenanceWindow::STATE_PENDING,
                'active_guard' => MaintenanceWindow::ACTIVE_GUARD,
            ]);
            $this->fail('The database accepted two current maintenance windows.');
        } catch (QueryException) {
            $this->assertDatabaseCount('maintenance_windows', 1);
        }

        $administrator->delete();
        $window->refresh();
        $this->assertNull($window->initiated_by);
        $this->assertNull($window->deactivation_requested_by);
        $this->assertNull($window->ended_by);
    }

    public function test_console_reconciles_a_pending_window_after_native_activation_succeeded(): void
    {
        $maintenanceMode = new FakeMaintenanceModeGateway;
        $maintenanceMode->active = true;
        $this->app->instance(MaintenanceModeGateway::class, $maintenanceMode);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $window = MaintenanceWindow::query()->create([
            'message' => 'Interrupted activation',
            'state' => MaintenanceWindow::STATE_PENDING,
            'active_guard' => MaintenanceWindow::ACTIVE_GUARD,
            'initiated_by' => $administrator->id,
        ]);

        $this->actingAs($administrator)
            ->get('/admin/maintenance')
            ->assertOk()
            ->assertSeeText('Application is in maintenance');

        $window->refresh();
        $this->assertSame(MaintenanceWindow::STATE_ACTIVE, $window->state);
        $this->assertNotNull($window->activated_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'maintenance.history_reconciled',
            'auditable_id' => $window->id,
        ]);
    }

    public function test_invalid_duplicate_current_history_fails_closed_and_is_audited(): void
    {
        DB::statement('DROP INDEX maintenance_windows_active_guard_unique');
        $maintenanceMode = new FakeMaintenanceModeGateway;
        $maintenanceMode->active = true;
        $this->app->instance(MaintenanceModeGateway::class, $maintenanceMode);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();

        foreach (['First conflicting row', 'Second conflicting row'] as $message) {
            MaintenanceWindow::query()->create([
                'message' => $message,
                'state' => MaintenanceWindow::STATE_PENDING,
                'active_guard' => MaintenanceWindow::ACTIVE_GUARD,
            ]);
        }

        $this->actingAs($administrator)
            ->get('/admin/maintenance')
            ->assertOk()
            ->assertSeeText('conflicting current records');

        $this->assertSame(
            [MaintenanceWindow::STATE_PENDING, MaintenanceWindow::STATE_PENDING],
            MaintenanceWindow::query()->orderBy('id')->pluck('state')->all(),
        );
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'maintenance.reconciliation_refused',
            'user_id' => $administrator->id,
        ]);
        $audit = AuditLog::query()
            ->where('event', 'maintenance.reconciliation_refused')
            ->sole();
        $this->assertSame('duplicate_current_history', $audit->context['reason_code']);
    }

    public function test_console_closes_deactivation_history_when_native_mode_is_already_inactive(): void
    {
        $maintenanceMode = new FakeMaintenanceModeGateway;
        $this->app->instance(MaintenanceModeGateway::class, $maintenanceMode);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $window = MaintenanceWindow::query()->create([
            'message' => 'Interrupted deactivation',
            'state' => MaintenanceWindow::STATE_DEACTIVATION_PENDING,
            'active_guard' => MaintenanceWindow::ACTIVE_GUARD,
            'initiated_by' => $administrator->id,
            'deactivation_requested_by' => $administrator->id,
            'activated_at' => now()->subMinutes(5),
            'deactivation_requested_at' => now()->subMinute(),
        ]);

        $this->actingAs($administrator)
            ->get('/admin/maintenance')
            ->assertOk()
            ->assertSeeText('Application is available');

        $window->refresh();
        $this->assertSame(MaintenanceWindow::STATE_ENDED, $window->state);
        $this->assertNull($window->active_guard);
        $this->assertSame($administrator->id, $window->ended_by);
        $this->assertNotNull($window->deactivated_at);
    }

    public function test_console_releases_a_pending_activation_when_native_mode_remained_inactive(): void
    {
        $maintenanceMode = new FakeMaintenanceModeGateway;
        $this->app->instance(MaintenanceModeGateway::class, $maintenanceMode);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $window = MaintenanceWindow::query()->create([
            'message' => 'Interrupted before native activation',
            'state' => MaintenanceWindow::STATE_PENDING,
            'active_guard' => MaintenanceWindow::ACTIVE_GUARD,
            'initiated_by' => $administrator->id,
        ]);

        $this->actingAs($administrator)
            ->get('/admin/maintenance')
            ->assertOk()
            ->assertSeeText('Application is available')
            ->assertSeeText('Laravel maintenance mode remained inactive');

        $window->refresh();
        $this->assertSame(MaintenanceWindow::STATE_ACTIVATION_FAILED, $window->state);
        $this->assertNull($window->active_guard);
        $this->assertSame('activation_interrupted', $window->failure_code);
        $this->assertNotNull($window->failure_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'maintenance.history_reconciled',
            'auditable_id' => $window->id,
        ]);
    }

    public function test_console_creates_bounded_history_for_native_maintenance_without_a_window(): void
    {
        $maintenanceMode = new FakeMaintenanceModeGateway;
        $maintenanceMode->active = true;
        $this->app->instance(MaintenanceModeGateway::class, $maintenanceMode);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();

        $this->actingAs($administrator)
            ->get('/admin/maintenance')
            ->assertOk();

        $this->assertDatabaseCount('maintenance_windows', 1);
        $window = MaintenanceWindow::query()->sole();
        $this->assertSame(MaintenanceWindow::STATE_ACTIVE, $window->state);
        $this->assertSame(MaintenanceWindow::ACTIVE_GUARD, $window->active_guard);
        $this->assertNull($window->initiated_by);
        $this->assertSame('Maintenance activated outside the Admin console.', $window->message);
    }

    public function test_activation_lock_contention_is_audited_and_returned_as_a_recoverable_error(): void
    {
        config(['maintenance.lock_wait_seconds' => 0]);
        $maintenanceMode = new FakeMaintenanceModeGateway;
        $this->app->instance(MaintenanceModeGateway::class, $maintenanceMode);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $lock = Cache::store(config('cache.default'))
            ->lock('myapes:maintenance-transition', 10);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($administrator)
                ->from('/admin/maintenance')
                ->post('/admin/maintenance/activate', [
                    'message' => 'Contended maintenance',
                    'confirm_activation' => '1',
                ])
                ->assertRedirect('/admin/maintenance')
                ->assertSessionHasErrors('maintenance');
        } finally {
            $lock->release();
        }

        $this->assertFalse($maintenanceMode->active);
        $this->assertDatabaseCount('maintenance_windows', 0);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'maintenance.activation_refused',
            'user_id' => $administrator->id,
        ]);
    }

    public function test_activation_history_failure_keeps_native_maintenance_active_for_reconciliation(): void
    {
        $maintenanceMode = new FakeMaintenanceModeGateway;
        $this->app->instance(MaintenanceModeGateway::class, $maintenanceMode);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        MaintenanceWindow::updating(static function (MaintenanceWindow $window): void {
            if ($window->state === MaintenanceWindow::STATE_ACTIVE) {
                throw new \RuntimeException('database secret detail');
            }
        });

        $this->actingAs($administrator)
            ->from('/admin/maintenance')
            ->post('/admin/maintenance/activate', [
                'message' => 'History finalization test',
                'confirm_activation' => '1',
            ])
            ->assertRedirect('/admin/maintenance')
            ->assertSessionHasErrors('maintenance');

        $this->assertTrue($maintenanceMode->active);
        $window = MaintenanceWindow::query()->sole();
        $this->assertSame(MaintenanceWindow::STATE_PENDING, $window->state);
        $this->assertSame(MaintenanceWindow::ACTIVE_GUARD, $window->active_guard);
        $audit = AuditLog::query()
            ->where('event', 'maintenance.history_finalization_failed')
            ->sole();
        $this->assertSame('activation_history_update_failed', $audit->context['reason_code']);
        $this->assertStringNotContainsString('secret', json_encode($audit->context, JSON_THROW_ON_ERROR));
    }

    public function test_deactivation_history_failure_keeps_the_application_up_for_reconciliation(): void
    {
        $maintenanceMode = new FakeMaintenanceModeGateway;
        $maintenanceMode->active = true;
        $this->app->instance(MaintenanceModeGateway::class, $maintenanceMode);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $window = MaintenanceWindow::query()->create([
            'message' => 'Deactivation history test',
            'state' => MaintenanceWindow::STATE_ACTIVE,
            'active_guard' => MaintenanceWindow::ACTIVE_GUARD,
            'initiated_by' => $administrator->id,
            'activated_at' => now()->subMinute(),
        ]);
        MaintenanceWindow::updating(static function (MaintenanceWindow $candidate): void {
            if ($candidate->state === MaintenanceWindow::STATE_ENDED) {
                throw new \RuntimeException('database secret detail');
            }
        });

        $this->actingAs($administrator)
            ->from('/admin/maintenance')
            ->post('/admin/maintenance/deactivate', [
                'confirm_deactivation' => '1',
            ])
            ->assertRedirect('/admin/maintenance')
            ->assertSessionHasErrors('maintenance');

        $this->assertFalse($maintenanceMode->active);
        $window->refresh();
        $this->assertSame(MaintenanceWindow::STATE_DEACTIVATION_PENDING, $window->state);
        $this->assertSame($administrator->id, $window->deactivation_requested_by);
        $audit = AuditLog::query()
            ->where('event', 'maintenance.history_finalization_failed')
            ->sole();
        $this->assertSame('deactivation_history_update_failed', $audit->context['reason_code']);
        $this->assertStringNotContainsString('secret', json_encode($audit->context, JSON_THROW_ON_ERROR));
    }

    public function test_deactivation_lock_contention_leaves_native_maintenance_untouched(): void
    {
        config(['maintenance.lock_wait_seconds' => 0]);
        $maintenanceMode = new FakeMaintenanceModeGateway;
        $maintenanceMode->active = true;
        $this->app->instance(MaintenanceModeGateway::class, $maintenanceMode);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        MaintenanceWindow::query()->create([
            'message' => 'Contended deactivation',
            'state' => MaintenanceWindow::STATE_ACTIVE,
            'active_guard' => MaintenanceWindow::ACTIVE_GUARD,
            'initiated_by' => $administrator->id,
            'activated_at' => now(),
        ]);
        $lock = Cache::store(config('cache.default'))
            ->lock('myapes:maintenance-transition', 10);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($administrator)
                ->from('/admin/maintenance')
                ->post('/admin/maintenance/deactivate', [
                    'confirm_deactivation' => '1',
                ])
                ->assertRedirect('/admin/maintenance')
                ->assertSessionHasErrors('maintenance');
        } finally {
            $lock->release();
        }

        $this->assertTrue($maintenanceMode->active);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'maintenance.deactivation_refused',
            'user_id' => $administrator->id,
        ]);
    }

    public function test_console_reports_reconciliation_lock_contention_without_a_server_error(): void
    {
        config(['maintenance.lock_wait_seconds' => 0]);
        $maintenanceMode = new FakeMaintenanceModeGateway;
        $this->app->instance(MaintenanceModeGateway::class, $maintenanceMode);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $lock = Cache::store(config('cache.default'))
            ->lock('myapes:maintenance-transition', 10);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($administrator)
                ->get('/admin/maintenance')
                ->assertOk()
                ->assertSeeText('Maintenance history is busy');
        } finally {
            $lock->release();
        }

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'maintenance.reconciliation_refused',
            'user_id' => $administrator->id,
        ]);
    }

    public function test_console_renders_current_details_and_only_the_latest_twenty_five_windows(): void
    {
        $maintenanceMode = new FakeMaintenanceModeGateway;
        $maintenanceMode->active = true;
        $this->app->instance(MaintenanceModeGateway::class, $maintenanceMode);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create(['name' => 'Maintenance Operator']);

        foreach (range(1, 26) as $number) {
            MaintenanceWindow::query()->create([
                'message' => $number === 1
                    ? 'Oldest-only maintenance marker'
                    : "Historical window {$number}",
                'state' => MaintenanceWindow::STATE_ENDED,
                'initiated_by' => $administrator->id,
                'ended_by' => $administrator->id,
                'activated_at' => now()->subDays(30 - $number),
                'deactivated_at' => now()->subDays(30 - $number)->addHour(),
            ]);
        }

        MaintenanceWindow::query()->create([
            'message' => 'Current operator message',
            'planned_end_at' => now()->addHour(),
            'state' => MaintenanceWindow::STATE_ACTIVE,
            'active_guard' => MaintenanceWindow::ACTIVE_GUARD,
            'initiated_by' => $administrator->id,
            'activated_at' => now(),
        ]);

        $this->actingAs($administrator)
            ->get('/admin/maintenance')
            ->assertOk()
            ->assertSeeText('Current operator message')
            ->assertSeeText('Maintenance Operator')
            ->assertSeeText('Historical window 26')
            ->assertDontSeeText('Oldest-only maintenance marker')
            ->assertSee('<table', false)
            ->assertSee('<caption', false);
    }
}
