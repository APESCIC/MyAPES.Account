<?php

namespace Tests\Feature;

use App\Contracts\ModuleLifecycleManager;
use App\Contracts\ModuleNavigationProvider;
use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Core\Extensions\Plugins\PluginEnablement;
use App\Exceptions\ModuleLifecycleException;
use App\Http\Middleware\EnsureModuleAvailable;
use App\Http\Middleware\EnsurePluginEnabled;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StructureEnablementWave2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_migration_preserves_module_installation_rows_in_module_plugins(): void
    {
        $this->assertTrue(Schema::hasTable('module_plugins'));
        $this->assertFalse(Schema::hasTable('module_installations'));
        $this->assertTrue(Schema::hasTable('module_plugin_settings'));
        $this->assertFalse(Schema::hasTable('module_settings'));

        $keys = ModuleInstallation::query()
            ->orderBy('sub_core_key')
            ->orderBy('module_key')
            ->get()
            ->map->instanceKey()
            ->all();

        $this->assertContains('apes-cic:tickets', $keys);
        $this->assertContains('shelter-rescue:tickets', $keys);
        $this->assertContains('pet-care-clinic:consultations', $keys);
        $this->assertContains('pet-care-clinic:pet-profiles', $keys);

        $this->assertSame(
            ModuleInstallation::query()->count(),
            DB::table('module_plugins')->count(),
        );
    }

    public function test_disabling_tickets_in_shelter_404s_hides_nav_and_leaves_cic_working(): void
    {
        $user = User::factory()->create();

        ModuleInstallation::query()
            ->where('sub_core_key', 'shelter-rescue')
            ->where('module_key', 'tickets')
            ->update([
                'enabled' => false,
                'disabled_at' => now(),
                'updated_at' => now(),
            ]);

        $this->assertFalse(app(PluginEnablement::class)->isEnabled('shelter-rescue', 'tickets'));

        $this->actingAs($user)
            ->get('/shelter/tickets')
            ->assertNotFound();

        $this->actingAs($user)
            ->get('/apes-cic/tickets')
            ->assertOk();

        $nav = app(ModuleNavigationProvider::class)->forUser($user);
        $shelterItems = collect($nav)
            ->first(fn ($item) => $item->subCore->key === 'shelter-rescue');
        $pluginKeys = collect($shelterItems?->modules ?? [])
            ->map(fn ($item) => $item->moduleKey)
            ->all();
        $this->assertNotContains('tickets', $pluginKeys);
    }

    public function test_cannot_disable_pet_profiles_while_consultations_enabled(): void
    {
        $admin = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $this->actingAs($admin);

        $petProfiles = ModuleInstallation::query()
            ->where('sub_core_key', 'pet-care-clinic')
            ->where('module_key', 'pet-profiles')
            ->firstOrFail();

        try {
            app(ModuleLifecycleManager::class)->disable(
                $admin,
                'pet-care-clinic',
                'pet-profiles',
                (int) $petProfiles->lock_version,
            );
            $this->fail('Expected enabled_dependent refusal.');
        } catch (ModuleLifecycleException $exception) {
            $this->assertSame('enabled_dependent', $exception->reason);
        }
    }

    public function test_cannot_enable_consultations_without_pet_profiles(): void
    {
        $admin = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $this->actingAs($admin);
        $lifecycle = app(ModuleLifecycleManager::class);

        $consultations = ModuleInstallation::query()
            ->where('sub_core_key', 'pet-care-clinic')
            ->where('module_key', 'consultations')
            ->firstOrFail();
        $lifecycle->disable(
            $admin,
            'pet-care-clinic',
            'consultations',
            (int) $consultations->lock_version,
        );

        $petProfiles = ModuleInstallation::query()
            ->where('sub_core_key', 'pet-care-clinic')
            ->where('module_key', 'pet-profiles')
            ->firstOrFail();
        $lifecycle->disable(
            $admin,
            'pet-care-clinic',
            'pet-profiles',
            (int) $petProfiles->lock_version,
        );

        $consultations = $consultations->fresh();

        try {
            app(PluginEnablement::class)->enable(
                $admin,
                'pet-care-clinic',
                'consultations',
                (int) $consultations->lock_version,
            );
            $this->fail('Expected dependency_unavailable refusal.');
        } catch (ModuleLifecycleException $exception) {
            $this->assertSame('dependency_unavailable', $exception->reason);
        }
    }

    public function test_admin_modules_and_plugins_views_are_separate_and_gated(): void
    {
        $admin = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($admin)
            ->get(route('admin.organisation-modules.index'))
            ->assertOk()
            ->assertSee('Admin modules')
            ->assertSee('Tickets');

        $this->actingAs($admin)
            ->get(route('admin.modules.index'))
            ->assertOk()
            ->assertSee('Admin plugins')
            ->assertSee('Compatible:');

        $this->actingAs($staff)
            ->get(route('admin.organisation-modules.index'))
            ->assertForbidden();

        $this->actingAs($staff)
            ->get(route('admin.modules.index'))
            ->assertForbidden();
    }

    public function test_disabled_plugin_permissions_are_not_offered_in_access_editors(): void
    {
        $admin = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $this->actingAs($admin);
        $enablement = app(PluginEnablement::class);

        $this->assertTrue(
            $enablement->isOfferedInAccessEditors('shelter-rescue.tickets.view-all'),
        );

        $installation = ModuleInstallation::query()
            ->where('sub_core_key', 'shelter-rescue')
            ->where('module_key', 'tickets')
            ->firstOrFail();

        ModuleInstallation::query()
            ->whereKey($installation->id)
            ->update([
                'enabled' => false,
                'disabled_at' => now(),
                'updated_at' => now(),
            ]);

        $this->assertFalse(
            $enablement->isOfferedInAccessEditors('shelter-rescue.tickets.view-all'),
        );
        $this->assertTrue(
            $enablement->isOfferedInAccessEditors('apes-cic.tickets.view-all'),
        );
        $this->assertTrue(
            $enablement->isOfferedInAccessEditors('admin.users.view'),
        );
    }

    public function test_runtime_contract_exposes_enablements_mirroring_shipped_instances(): void
    {
        $contract = json_decode(
            (string) file_get_contents(resource_path('data/module-runtime-contract.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(2, $contract['schema_version']);
        $this->assertSame(
            $contract['shipped_instances'],
            $contract['enablements']['shipped'],
        );
        $this->assertSame(
            $contract['legacy_visible_instances'],
            $contract['enablements']['legacy_visible'],
        );
    }

    public function test_plugin_enabled_middleware_wraps_legacy_module_available_alias(): void
    {
        $this->assertTrue(class_exists(EnsurePluginEnabled::class));
        $this->assertTrue(is_subclass_of(EnsureModuleAvailable::class, object::class)
            || class_exists(EnsureModuleAvailable::class));

        $source = (string) file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringContainsString("'plugin.enabled' => EnsurePluginEnabled::class", $source);
        $this->assertStringContainsString("'module.available' => EnsureModuleAvailable::class", $source);
    }
}
