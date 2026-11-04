<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Admin overview "Plugin alerts" count is explained and actionable (#474).
 */
class PluginAlertsExplainedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_a_healthy_install_counts_no_alerts_and_explains_the_notes(): void
    {
        $admin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();

        $response = $this->actingAs($admin)->get(route('admin.index'))->assertOk();
        $dashboard = $response->viewData('dashboard');

        $this->assertSame([], $dashboard['module_alerts_action']);
        $this->assertNotEmpty($dashboard['module_alerts_info']);
        $this->assertSame(['incompatible'], collect($dashboard['module_alerts_info'])->pluck('kind')->unique()->values()->all());

        $response
            ->assertSee('<div data-kpi="module-alerts"><a href="#plugin-alerts"', false)
            ->assertSee('id="plugin-alerts"', false)
            ->assertSeeText('No plugin warnings.')
            ->assertSeeText('This plugin is not made for this module, so it is never offered here. Nothing to do.');
    }

    public function test_clicking_the_count_lists_each_alert_with_a_reason_and_a_fix_link(): void
    {
        ModuleInstallation::query()
            ->where('sub_core_key', 'shelter-rescue')
            ->where('module_key', 'cases')
            ->update(['enabled' => false, 'disabled_at' => now()]);
        $admin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();

        $response = $this->actingAs($admin)->get(route('admin.index'))->assertOk();
        $dashboard = $response->viewData('dashboard');

        $this->assertCount(1, $dashboard['module_alerts_action']);
        $alert = $dashboard['module_alerts_action'][0];
        $this->assertSame('disabled', $alert['kind']);
        $this->assertSame(route('admin.modules.index'), $alert['fix_url']);

        $response
            ->assertSee('aria-label="1 plugin alerts need action. Show the list."', false)
            ->assertSee('data-alert-kind="disabled" data-alert-severity="action"', false)
            ->assertSeeText('This plugin is turned off, so people in this module cannot use it.')
            ->assertSeeText('Turn on in Plugins')
            ->assertDontSeeText('No plugin warnings.');
    }
}
