<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_figures_match_database_counts_without_emails(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create([
            'two_factor_confirmed_at' => now(),
        ]);
        User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create([
            'suspended_at' => now(),
            'email' => 'secret-staff@example.test',
        ]);
        AuditLog::create([
            'user_id' => null,
            'event' => 'auth.public_login_failed',
            'context' => ['credential' => 'should-not-appear'],
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.security.index'))
            ->assertOk()
            ->assertSee('data-stat="two_factor_enabled"', false)
            ->assertSee('data-stat="suspended"', false)
            ->assertSee('data-stat="failed_sign_ins"', false)
            ->getContent();

        $this->assertMatchesRegularExpression('/data-stat="two_factor_enabled">\s*1\s*</', $html);
        $this->assertMatchesRegularExpression('/data-stat="suspended">\s*1\s*</', $html);
        $this->assertMatchesRegularExpression('/data-stat="failed_sign_ins">\s*1\s*</', $html);
        $this->assertStringNotContainsString('secret-staff@example.test', $html);
        $this->assertStringNotContainsString('should-not-appear', $html);
    }
}
