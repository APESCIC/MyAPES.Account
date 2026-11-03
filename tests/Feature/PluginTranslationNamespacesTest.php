<?php

namespace Tests\Feature;

use App\Console\Commands\LangCheckCommand;
use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Core\Extensions\Plugins\PluginRegistry;
use App\Services\AuthorizationProfile;
use App\Services\Localisation\TranslationKeyChecker;
use App\Services\ModuleInstallationSynchronizer;
use App\Services\ModuleProjectionCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PluginTranslationNamespacesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_all_five_plugin_namespaces_resolve_plugin_name_keys(): void
    {
        $expected = [
            'cases' => 'Cases',
            'tickets' => 'Tickets',
            'recruitment' => 'Recruitment',
            'consultations' => 'Consultations',
            'pet_profiles' => 'Pet Profiles',
        ];

        foreach ($expected as $namespace => $name) {
            $this->assertSame($name, __(''.$namespace.'::plugin.name'));
            $this->assertNotSame($namespace.'::plugin.name', __(''.$namespace.'::plugin.description'));
            $this->assertNotSame('', __(''.$namespace.'::plugin.description'));
        }

        $registry = app(PluginRegistry::class);
        foreach (['cases', 'tickets', 'recruitment', 'consultations', 'pet-profiles'] as $slug) {
            $manifest = $registry->plugin($slug);
            $ns = str_replace('-', '_', $slug);
            $this->assertSame($ns.'::plugin.name', $manifest->nameKey);
            $this->assertSame($ns.'::plugin.description', $manifest->descriptionKey);
            $this->assertSame(__($ns.'::plugin.name'), $manifest->label());
            $this->assertSame(__($ns.'::plugin.description'), $manifest->descriptionLabel());
        }
    }

    public function test_admin_plugins_index_renders_names_and_descriptions_from_lang(): void
    {
        $superAdmin = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();

        $response = $this->actingAs($superAdmin)->get(route('admin.modules.index'));

        $response->assertOk();
        $response->assertSeeText('Cases');
        $response->assertSeeText('Rescue and welfare case records.');
        $response->assertSeeText('Tickets');
        $response->assertSeeText('Support requests and threaded responses.');
        $response->assertSeeText('Recruitment');
        $response->assertSeeText('Advertise roles and manage applications.');
        $response->assertSeeText('Consultations');
        $response->assertSeeText('Pet Profiles');
        $response->assertSeeText('Animal identity, care and welfare profiles.');
    }

    public function test_vendor_override_wins_for_plugin_namespace(): void
    {
        $overrideDir = lang_path('vendor/recruitment/en_GB');
        File::ensureDirectoryExists($overrideDir);
        File::put($overrideDir.'/plugin.php', <<<'PHP'
<?php

return [
    'name' => 'Recruitment Override',
    'short_name' => 'Recruitment Override',
    'description' => 'Vendor override description.',
    'keywords' => ['override'],
];
PHP);

        try {
            // Force the translator to reload groups so the vendor file wins.
            app('translator')->setLoaded([]);

            $this->assertSame('Recruitment Override', __('recruitment::plugin.name'));
            $this->assertSame('Vendor override description.', __('recruitment::plugin.description'));
            $this->assertSame(
                'Recruitment Override',
                app(PluginRegistry::class)->plugin('recruitment')->label(),
            );
        } finally {
            File::deleteDirectory(lang_path('vendor/recruitment'));
            app('translator')->setLoaded([]);
        }
    }

    public function test_disabled_plugin_does_not_break_admin_plugins_or_other_namespaces(): void
    {
        $installation = ModuleInstallation::query()
            ->where('sub_core_key', 'apes-cic')
            ->where('module_key', 'recruitment')
            ->firstOrFail();
        $installation->forceFill([
            'enabled' => false,
            'disabled_at' => now(),
        ])->save();
        app(ModuleProjectionCache::class)->invalidate();

        $this->assertSame('Tickets', __('tickets::plugin.name'));
        $this->assertSame('Recruitment', __('recruitment::plugin.name'));

        $superAdmin = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();

        $this->actingAs($superAdmin)
            ->get(route('admin.modules.index'))
            ->assertOk()
            ->assertSeeText('Tickets')
            ->assertSeeText('Recruitment');
    }

    public function test_lang_check_report_only_succeeds_and_detects_fixture_missing_key(): void
    {
        $exit = Artisan::call('lang:check');
        $this->assertSame(0, $exit);

        $checker = new TranslationKeyChecker;
        $fixture = <<<'PHP'
<?php
echo __('this.key.definitely.missing.for.wave1');
PHP;
        $extracted = $checker->extractKeysFromContents($fixture, 'app/Http/Example.php');
        $this->assertSame('this.key.definitely.missing.for.wave1', $extracted[0]['key']);

        $failExit = Artisan::call('lang:check', ['--fail-on-missing' => true]);
        $this->assertSame(LangCheckCommand::SUCCESS, $failExit, Artisan::output());
    }
}
