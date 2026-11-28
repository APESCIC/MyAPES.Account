<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_work_together_and_export_is_audited_without_secrets(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create([
            'name' => 'Ada Admin',
        ]);
        $subject = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'name' => 'Sam Subject',
        ]);

        AuditLog::create([
            'user_id' => $admin->id,
            'event' => 'auth.public_login_failed',
            'auditable_type' => $subject->getMorphClass(),
            'auditable_id' => $subject->id,
            'context' => [
                'password' => 'never-show',
                'reason_code' => 'bad_credentials',
            ],
        ]);
        AuditLog::create([
            'user_id' => $admin->id,
            'event' => 'module.enabled',
            'context' => ['module_key' => 'ignore-me'],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.security.audit-log', [
                'actor' => 'Ada',
                'subject' => 'Sam',
                'event' => 'auth.public_login_failed',
            ]))
            ->assertOk()
            ->assertSee('auth.public_login_failed', false)
            ->assertSee('Ada Admin', false)
            ->assertSee('Sam Subject', false)
            ->assertSee('bad_credentials', false)
            ->assertDontSee('never-show')
            ->assertDontSee('ignore-me');

        $this->actingAs($admin)
            ->get(route('admin.security.audit-log.export', [
                'event' => 'auth.public_login_failed',
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'admin.security.audit_log_exported',
            'user_id' => $admin->id,
        ]);
    }
}
