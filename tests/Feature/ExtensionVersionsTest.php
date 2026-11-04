<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\Extensions\ExtensionVersionSynchronizer;
use App\Core\Extensions\Models\ExtensionVersion;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plugin and module version management (#473).
 */
class ExtensionVersionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_admin_sees_a_version_for_every_plugin_and_module(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $response = $this->actingAs($admin)->get(route('admin.versions.index'))->assertOk()->assertSee('data-admin-versions', false);

        foreach (['apes-cic', 'shelter-rescue', 'pet-care-clinic'] as $module) {
            $response->assertSee('data-version-row="module:'.$module.'"', false);
        }
        foreach (['tickets', 'cases', 'recruitment', 'pet-profiles', 'consultations'] as $plugin) {
            $response->assertSee('data-version-row="plugin:'.$plugin.'"', false);
        }

        $this->assertSame(8, ExtensionVersion::query()->count());
        $response->assertSeeText('1.0.0');
    }

    public function test_a_bumped_version_shows_as_update_applied_with_a_date(): void
    {
        ExtensionVersion::query()->where('kind', 'plugin')->where('slug', 'tickets')->update(['version' => '0.9.0']);
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($admin)
            ->get(route('admin.versions.index'))
            ->assertSee('data-version-state="pending"', false)
            ->assertSeeText('Update pending');

        $this->travelTo(now()->setDate(2026, 11, 4));
        $result = app(ExtensionVersionSynchronizer::class)->synchronize();
        $this->assertSame(['recorded' => 0, 'updated' => 1], $result);

        $row = ExtensionVersion::query()->where('kind', 'plugin')->where('slug', 'tickets')->sole();
        $this->assertSame('1.0.0', $row->version);
        $this->assertSame('0.9.0', $row->previous_version);
        $this->assertTrue(DB::table('extension_version_history')
            ->where('slug', 'tickets')->where('from_version', '0.9.0')->where('to_version', '1.0.0')->exists());

        $this->actingAs($admin)
            ->get(route('admin.versions.index'))
            ->assertSee('data-version-row="plugin:tickets" data-version-state="updated"', false)
            ->assertSeeText('Update applied 04/11/2026')
            ->assertSeeText('Updated 0.9.0 to 1.0.0');
    }

    public function test_synchronising_again_without_changes_records_nothing(): void
    {
        $before = DB::table('extension_version_history')->count();

        $this->assertSame(['recorded' => 0, 'updated' => 0], app(ExtensionVersionSynchronizer::class)->synchronize());
        $this->assertSame($before, DB::table('extension_version_history')->count());
    }
}
