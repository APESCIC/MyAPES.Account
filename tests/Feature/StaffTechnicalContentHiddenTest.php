<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffTechnicalContentHiddenTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_staff_do_not_see_github_or_admin_only_help_items(): void
    {
        $staff = User::factory()->accessLevel(User::ROLE_STAFF)->create();

        $help = $this->actingAs($staff)->get(route('help.staff'))->assertOk()->getContent();
        $this->assertStringNotContainsString(__('help.staff.areas.admin'), $help);
        $this->assertDoesNotMatchRegularExpression('/\\b(OIDC|webhook|access matrix)\\b/i', $help);

        $dashboard = $this->actingAs($staff)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringNotContainsString('https://github.com/APESCIC/MyAPES-Account/issues/new/choose', $dashboard);
        $this->assertStringContainsString(route('staff.report-problem.create'), $dashboard);
    }
}
