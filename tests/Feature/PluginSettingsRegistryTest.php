<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleSetting;
use App\Modules\ModuleSettingsDescriptor;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use App\Services\ModuleSettingsRegistry;
use App\Services\ModuleSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Recruitment\Models\RecruitmentRole;
use Tests\TestCase;

class PluginSettingsRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_registry_declares_settings_contract_for_apes_cic_and_remaining_plugins(): void
    {
        $registry = app(ModuleSettingsRegistry::class);

        $tickets = $registry->descriptor('apes-cic', 'tickets');
        $this->assertTrue($tickets->supportsSettings);
        $this->assertSame(ModuleSettingsDescriptor::SCHEMA_WEBSITES_CATEGORIES, $tickets->schema);
        $this->assertSame('admin.modules.view', $tickets->viewPermission);
        $this->assertSame('admin.modules.manage', $tickets->managePermission);
        $this->assertSame('Settings', $tickets->navLabel);
        $this->assertSame(
            route('admin.modules.settings.edit', ['apes-cic', 'tickets']),
            $tickets->settingsUrl(),
        );

        $recruitment = $registry->descriptor('apes-cic', 'recruitment');
        $this->assertTrue($recruitment->supportsSettings);
        $this->assertSame(ModuleSettingsDescriptor::SCHEMA_RECRUITMENT_BOARD, $recruitment->schema);

        $this->assertFalse(
            $registry->descriptor('pet-care-clinic', 'consultations')->supportsSettings,
        );
        $this->assertFalse(
            $registry->descriptor('shelter-rescue', 'pet-profiles')->supportsSettings,
        );
        $this->assertNull(
            $registry->descriptor('pet-care-clinic', 'consultations')->settingsUrl(),
        );

        $this->assertSame(
            ['tickets', 'cases', 'recruitment'],
            $registry->configurableModuleKeys('apes-cic'),
        );
    }

    public function test_plugins_index_uses_registry_for_settings_links_and_no_settings_state(): void
    {
        $superAdmin = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();

        $response = $this->actingAs($superAdmin)->get(route('admin.modules.index'));

        $response->assertOk();
        $response->assertSee(route('admin.modules.settings.edit', ['apes-cic', 'tickets']));
        $response->assertSee(route('admin.modules.settings.edit', ['apes-cic', 'cases']));
        $response->assertSee(route('admin.modules.settings.edit', ['apes-cic', 'recruitment']));
        $response->assertSeeText('Settings');
        $response->assertSeeText('No configurable settings');
        $response->assertDontSee(
            route('admin.modules.settings.edit', ['pet-care-clinic', 'consultations']),
        );
        $response->assertDontSee(
            route('admin.modules.settings.edit', ['shelter-rescue', 'pet-profiles']),
        );
    }

    public function test_unauthorized_actors_cannot_open_or_update_plugin_settings(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $volunteer = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_VOLUNTEER)
            ->create();

        foreach ([$staff, $volunteer] as $actor) {
            foreach (['tickets', 'cases', 'recruitment'] as $moduleKey) {
                $this->actingAs($actor)
                    ->get(route('admin.modules.settings.edit', ['apes-cic', $moduleKey]))
                    ->assertForbidden();
                $this->actingAs($actor)
                    ->put(route('admin.modules.settings.update', ['apes-cic', $moduleKey]), [
                        'version' => 1,
                    ])
                    ->assertForbidden();
            }
        }
    }

    public function test_modules_without_settings_return_404_on_settings_urls(): void
    {
        $superAdmin = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();

        $this->actingAs($superAdmin)
            ->get(route('admin.modules.settings.edit', ['pet-care-clinic', 'consultations']))
            ->assertNotFound();
        $this->actingAs($superAdmin)
            ->get(route('admin.modules.settings.edit', ['shelter-rescue', 'pet-profiles']))
            ->assertNotFound();
    }

    public function test_super_admin_can_view_and_save_recruitment_settings(): void
    {
        $this->assertDatabaseHas('module_plugin_settings', [
            'sub_core_key' => 'apes-cic',
            'module_key' => 'recruitment',
        ]);

        $superAdmin = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $record = ModuleSetting::query()
            ->where('sub_core_key', 'apes-cic')
            ->where('module_key', 'recruitment')
            ->firstOrFail();

        $this->actingAs($superAdmin)
            ->get(route('admin.modules.settings.edit', ['apes-cic', 'recruitment']))
            ->assertOk()
            ->assertSeeText('Recruitment settings')
            ->assertSeeText('Show public roles board')
            ->assertSeeText('Allow public applications');

        $this->actingAs($superAdmin)
            ->put(route('admin.modules.settings.update', ['apes-cic', 'recruitment']), [
                'version' => $record->lock_version,
                'public_board_enabled' => '1',
                'public_apply_enabled' => '0',
            ])
            ->assertRedirect(route('admin.modules.settings.edit', ['apes-cic', 'recruitment']));

        $settings = app(ModuleSettingsService::class)->get('apes-cic', 'recruitment');
        $this->assertTrue($settings['public_board_enabled']);
        $this->assertFalse($settings['public_apply_enabled']);
        $this->assertTrue(app(ModuleSettingsService::class)->recruitmentPublicBoardEnabled());
        $this->assertFalse(app(ModuleSettingsService::class)->recruitmentPublicApplyEnabled());
    }

    public function test_disabling_public_board_hides_discovery_and_returns_404(): void
    {
        $superAdmin = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $record = ModuleSetting::query()
            ->where('sub_core_key', 'apes-cic')
            ->where('module_key', 'recruitment')
            ->firstOrFail();

        $this->actingAs($superAdmin)
            ->put(route('admin.modules.settings.update', ['apes-cic', 'recruitment']), [
                'version' => $record->lock_version,
                'public_board_enabled' => '0',
                'public_apply_enabled' => '1',
            ])
            ->assertRedirect();

        auth()->logout();

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSeeText('View open roles')
            ->assertDontSee('href="'.route('recruitment.index').'"', false);

        $this->get(route('recruitment.index'))->assertNotFound();
    }

    public function test_disabling_public_apply_blocks_submissions_while_board_stays_open(): void
    {
        $superAdmin = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $public = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();
        $staff = User::factory()->create();
        $role = RecruitmentRole::factory()
            ->open()
            ->category('volunteer')
            ->create([
                'created_by' => $staff->id,
                'title' => 'Apply gate role',
            ]);

        $record = ModuleSetting::query()
            ->where('sub_core_key', 'apes-cic')
            ->where('module_key', 'recruitment')
            ->firstOrFail();

        $this->actingAs($superAdmin)
            ->put(route('admin.modules.settings.update', ['apes-cic', 'recruitment']), [
                'version' => $record->lock_version,
                'public_board_enabled' => '1',
                'public_apply_enabled' => '0',
            ])
            ->assertRedirect();

        $this->get(route('recruitment.index'))->assertOk();
        $this->actingAs($public)
            ->get(route('recruitment.show', $role))
            ->assertOk()
            ->assertSeeText('Applications are not open for public roles right now');

        $this->actingAs($public)
            ->post(route('recruitment.apply', $role), [
                'statement' => 'I would like to help.',
            ])
            ->assertNotFound();
    }
}
