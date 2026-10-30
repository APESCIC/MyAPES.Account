<?php

namespace Tests\Feature;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceModeResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_activation_supplies_the_escaped_branded_public_response_and_retry_headers(): void
    {
        $this->fakeMaintenanceMode(false);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)
            ->create();

        $this->actingAs($administrator)
            ->post('/admin/maintenance/activate', [
                'message' => '<script>alert("unsafe")</script> Planned work',
                'confirm_activation' => '1',
            ])
            ->assertRedirect('/admin/maintenance');

        $this->get('/')
            ->assertServiceUnavailable()
            ->assertHeader('Retry-After', '60')
            ->assertHeader('Refresh', '60')
            ->assertSeeText('MyAPES Account')
            ->assertSeeText('<script>alert("unsafe")</script> Planned work')
            ->assertDontSee('<script>alert("unsafe")</script>', false)
            ->assertSeeText('service will not resume automatically');
    }

    public function test_health_remains_available_and_reports_maintenance_without_failing(): void
    {
        $this->fakeMaintenanceMode(false);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)
            ->create();
        $this->actingAs($administrator)
            ->post('/admin/maintenance/activate', [
                'message' => 'Health exception check',
                'confirm_activation' => '1',
            ])
            ->assertRedirect('/admin/maintenance');

        $this->get('/healthz')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('maintenance', true)
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.cache', 'ok');
    }

    public function test_staff_sign_in_page_remains_available_during_maintenance(): void
    {
        $this->fakeMaintenanceMode(true);

        $this->get('/staff/login')
            ->assertOk()
            ->assertSeeText('Staff Login');
    }

    public function test_authorized_administrator_can_reach_the_recovery_console_while_down(): void
    {
        $this->fakeMaintenanceMode(true);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)
            ->create();

        $this->actingAs($administrator)
            ->get('/admin/maintenance')
            ->assertOk()
            ->assertSeeText('Application is in maintenance')
            ->assertSeeText('End maintenance');
    }

    public function test_ordinary_staff_receive_the_branded_response_on_the_excepted_recovery_path(): void
    {
        $this->fakeMaintenanceMode(false);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)
            ->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $this->actingAs($administrator)
            ->post('/admin/maintenance/activate', [
                'message' => 'Protected recovery test',
                'confirm_activation' => '1',
            ])
            ->assertRedirect('/admin/maintenance');

        $this->actingAs($staff)
            ->get('/admin/maintenance')
            ->assertServiceUnavailable()
            ->assertHeader('Retry-After', '60')
            ->assertSeeText('Protected recovery test')
            ->assertDontSeeText('End maintenance');

        $audit = AuditLog::query()
            ->where('event', 'maintenance.recovery_access_denied')
            ->sole();
        $this->assertSame($staff->id, $audit->user_id);
        $this->assertSame([
            'action' => 'access_recovery',
            'method' => 'GET',
            'reason_code' => 'permission_denied',
            'route_name' => 'admin.maintenance.index',
        ], $audit->context);
    }

    public function test_json_clients_receive_only_the_sanitized_maintenance_contract(): void
    {
        $this->fakeMaintenanceMode(false);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)
            ->create();
        $this->actingAs($administrator)
            ->post('/admin/maintenance/activate', [
                'message' => 'Internal detail that JSON must not expose',
                'confirm_activation' => '1',
            ])
            ->assertRedirect('/admin/maintenance');

        $this->withHeader('Accept', 'application/json')
            ->get('/')
            ->assertServiceUnavailable()
            ->assertHeader('Retry-After', '60')
            ->assertExactJson([
                'message' => 'Service Unavailable',
                'maintenance' => true,
            ])
            ->assertDontSee('Internal detail');
    }

    public function test_authenticated_administrator_oidc_start_redirects_to_recovery_while_down(): void
    {
        $this->fakeMaintenanceMode(true);
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)
            ->create();

        $this->actingAs($administrator)
            ->get('/staff/auth/login')
            ->assertRedirect('/admin/maintenance');
    }

    public function test_authenticated_ordinary_staff_oidc_start_returns_maintenance_response(): void
    {
        $this->fakeMaintenanceMode(true);
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($staff)
            ->get('/staff/auth/login')
            ->assertServiceUnavailable()
            ->assertSeeText('Temporarily unavailable');
    }
}
