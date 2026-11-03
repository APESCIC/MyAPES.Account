<?php

namespace Tests\Feature;

use App\Contracts\ModuleNavigationProvider;
use App\Core\Accounts\User;
use App\Core\Auth\OidcFlow;
use App\Core\Auth\OidcIdentity;
use App\Core\Extensions\Models\OrganisationModule;
use App\Core\Extensions\Modules\ModulePackageRegistry;
use App\Core\Extensions\Plugins\PluginDependency;
use App\Core\Extensions\Plugins\PluginManifest;
use App\Core\Extensions\Plugins\PluginRegistry;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Tests\TestCase;

class StructureContractsWave1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_module_and_plugin_manifests_are_registered_from_packages(): void
    {
        $modules = app(ModulePackageRegistry::class);
        $plugins = app(PluginRegistry::class);

        $this->assertSame(
            ['apes-cic', 'pet-care-clinic', 'shelter-rescue'],
            array_keys($modules->modules()),
        );
        $this->assertSame('/apes-cic', $modules->module('apes-cic')->routePrefix);
        $this->assertSame(
            ['cases', 'consultations', 'pet-profiles', 'recruitment', 'tickets'],
            array_keys($plugins->plugins()),
        );

        $recruitment = $plugins->plugin('recruitment');
        $this->assertSame('recruitment', $recruitment->translationNamespace);
        $this->assertSame('recruitment::plugin.keywords', $recruitment->searchKeywordsKey);
        $this->assertSame('^0.37.0', $recruitment->requiresCore);
    }

    public function test_plugin_registry_rejects_missing_dependency_and_cycles(): void
    {
        $registry = new PluginRegistry;
        $registry->register(PluginManifest::make(
            slug: 'alpha',
            name: 'Alpha',
            description: 'Alpha',
            version: '1.0.0',
            requiresCore: '*',
            compatibleModules: ['apes-cic'],
            shippedModules: ['apes-cic'],
            permissions: [],
            dependencies: [new PluginDependency('missing')],
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing plugin');
        $registry->validate(['apes-cic'], '0.37.3');
    }

    public function test_plugin_registry_rejects_dependency_cycles(): void
    {
        $registry = new PluginRegistry;
        $registry->register(PluginManifest::make(
            slug: 'alpha',
            name: 'Alpha',
            description: 'Alpha',
            version: '1.0.0',
            requiresCore: '*',
            compatibleModules: ['apes-cic'],
            shippedModules: ['apes-cic'],
            permissions: [],
            dependencies: [new PluginDependency('beta')],
        ));
        $registry->register(PluginManifest::make(
            slug: 'beta',
            name: 'Beta',
            description: 'Beta',
            version: '1.0.0',
            requiresCore: '*',
            compatibleModules: ['apes-cic'],
            shippedModules: ['apes-cic'],
            permissions: [],
            dependencies: [new PluginDependency('alpha')],
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cycle');
        $registry->validate(['apes-cic'], '0.37.3');
    }

    public function test_plugin_registry_rejects_unknown_compatible_module(): void
    {
        $registry = new PluginRegistry;
        $registry->register(PluginManifest::make(
            slug: 'alpha',
            name: 'Alpha',
            description: 'Alpha',
            version: '1.0.0',
            requiresCore: '*',
            compatibleModules: ['not-a-module'],
            shippedModules: [],
            permissions: [],
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown module');
        $registry->validate(['apes-cic'], '0.37.3');
    }

    public function test_plugin_registry_rejects_requires_core_mismatch(): void
    {
        $registry = new PluginRegistry;
        $registry->register(PluginManifest::make(
            slug: 'alpha',
            name: 'Alpha',
            description: 'Alpha',
            version: '1.0.0',
            requiresCore: '^9.0.0',
            compatibleModules: ['apes-cic'],
            shippedModules: ['apes-cic'],
            permissions: [],
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires Core');
        $registry->validate(['apes-cic'], '0.37.3');
    }

    public function test_disabling_an_organisation_module_hides_nav_and_returns_404(): void
    {
        $admin = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();

        OrganisationModule::query()->updateOrCreate(
            ['slug' => 'apes-cic'],
            [
                'enabled' => false,
                'disabled_at' => now(),
                'disabled_by' => $admin->id,
            ],
        );

        $this->assertFalse(app(ModulePackageRegistry::class)->isEnabled('apes-cic'));

        $this->actingAs($admin)
            ->get(route('apes-cic.index'))
            ->assertNotFound();

        $nav = app(ModuleNavigationProvider::class)->forUser($admin);
        $slugs = collect($nav)->map(fn ($item) => $item->subCore->key)->all();
        $this->assertNotContains('apes-cic', $slugs);
    }

    public function test_admin_can_re_enable_organisation_module(): void
    {
        $admin = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();

        OrganisationModule::query()->updateOrCreate(
            ['slug' => 'shelter-rescue'],
            ['enabled' => false, 'disabled_at' => now()],
        );

        $this->actingAs($admin)
            ->post(route('admin.organisation-modules.transition', 'shelter-rescue'), [
                'action' => 'enable',
            ])
            ->assertRedirect(route('admin.organisation-modules.index'));

        $this->assertTrue(app(ModulePackageRegistry::class)->isEnabled('shelter-rescue'));
    }

    public function test_app_service_provider_does_not_reference_plugin_policies(): void
    {
        $source = file_get_contents(app_path('Providers/AppServiceProvider.php'));

        $this->assertStringNotContainsString('SupportTicketPolicy', $source);
        $this->assertStringNotContainsString('RecruitmentRolePolicy', $source);
        $this->assertStringNotContainsString('recruitmentPublicBoardEnabled', $source);
        $this->assertStringNotContainsString('ModuleCatalogueProjection', $source);
        $this->assertStringNotContainsString('Gate::policy', $source);
    }

    public function test_core_auth_classes_live_under_app_core_auth(): void
    {
        $this->assertTrue(class_exists(OidcIdentity::class));
        $this->assertTrue(class_exists(OidcFlow::class));
        $this->assertFileDoesNotExist(app_path('Auth/OidcIdentity.php'));
    }

    public function test_core_tree_does_not_import_modules_or_plugins_namespaces(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Core')),
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            $this->assertDoesNotMatchRegularExpression(
                '/\\\\use\\s+Modules\\\\/',
                $contents,
                $file->getPathname(),
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\\\\use\\s+Plugins\\\\/',
                $contents,
                $file->getPathname(),
            );
            $this->assertStringNotContainsString('use Modules\\', $contents, $file->getPathname());
            $this->assertStringNotContainsString('use Plugins\\', $contents, $file->getPathname());
        }
    }

    public function test_runtime_contract_schema_version_two_exposes_modules_and_plugins(): void
    {
        $contract = json_decode(
            (string) file_get_contents(resource_path('data/module-runtime-contract.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(2, $contract['schema_version']);
        $this->assertSame($contract['sub_cores'], $contract['modules']);
        $this->assertSame($contract['module_types'], $contract['plugins']);
    }

    public function test_plugins_list_command_prints_manifests(): void
    {
        $exitCode = Artisan::call('myapes:plugins-list');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('recruitment::plugin.keywords', $output);
        $this->assertStringContainsString('translationNamespace=recruitment', $output);
    }
}
