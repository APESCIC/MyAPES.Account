<?php

namespace Tests\Feature;

use App\Core\Extensions\Models\ModuleInstallation;
use App\Exceptions\ModuleLifecycleException;
use App\Services\ModuleInstallationSynchronizer;
use App\Services\ModuleRollbackCompatibilityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ModuleRollbackCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporaryDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            if (is_dir($directory)) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $directory,
                        \FilesystemIterator::SKIP_DOTS,
                    ),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );

                foreach ($files as $file) {
                    $file->isDir()
                        ? rmdir($file->getPathname())
                        : unlink($file->getPathname());
                }

                rmdir($directory);
            }
        }

        parent::tearDown();
    }

    public function test_the_source_controlled_runtime_manifest_represents_the_current_release(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(resource_path('data/module-runtime-contract.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('0.42.6', trim((string) file_get_contents(base_path('VERSION'))));
        $this->assertSame('0.42.6', $manifest['application_version']);
        $this->assertSame([
            'apes-cic:cases',
            'apes-cic:recruitment',
            'apes-cic:tickets',
            'pet-care-clinic:consultations',
            'pet-care-clinic:pet-profiles',
            'pet-care-clinic:tickets',
            'shelter-rescue:cases',
            'shelter-rescue:pet-profiles',
            'shelter-rescue:tickets',
        ], $manifest['shipped_instances']);
        $this->assertSame([
            'apes-cic:tickets',
            'pet-care-clinic:consultations',
            'pet-care-clinic:pet-profiles',
            'shelter-rescue:cases',
            'shelter-rescue:pet-profiles',
        ], $manifest['legacy_visible_instances']);

        $result = app(ModuleRollbackCompatibilityChecker::class)
            ->check(base_path());
        $this->assertSame('manifest', $result['contract']);
        $this->assertSame(9, $result['installations']);
        $this->assertSame('0.42.6', $result['target_version']);
    }

    public function test_a_legacy_target_without_a_manifest_requires_exactly_five_enabled_baselines(): void
    {
        ModuleInstallation::query()
            ->where('sub_core_key', 'apes-cic')
            ->where('module_key', 'cases')
            ->delete();
        ModuleInstallation::query()
            ->where('sub_core_key', 'apes-cic')
            ->where('module_key', 'recruitment')
            ->delete();
        ModuleInstallation::query()
            ->where('sub_core_key', 'shelter-rescue')
            ->where('module_key', 'tickets')
            ->delete();
        ModuleInstallation::query()
            ->where('sub_core_key', 'pet-care-clinic')
            ->where('module_key', 'tickets')
            ->delete();
        $legacy = $this->temporaryRelease();

        $this->assertSame(
            [
                'contract' => 'legacy-v0.8.3',
                'installations' => 5,
                'target_version' => '0.8.3',
            ],
            app(ModuleRollbackCompatibilityChecker::class)->check($legacy),
        );

        $installation = ModuleInstallation::query()->firstOrFail();
        $installation->forceFill([
            'enabled' => false,
            'disabled_at' => now(),
        ])->save();

        $this->expectException(ModuleLifecycleException::class);
        $this->expectExceptionMessage('Module rollback compatibility failed.');
        app(ModuleRollbackCompatibilityChecker::class)->check($legacy);
    }

    public function test_v0121_target_rejects_the_current_persisted_installations(): void
    {
        $this->assertSame(9, ModuleInstallation::query()->count());

        try {
            app(ModuleRollbackCompatibilityChecker::class)
                ->check($this->v0121Release());
            $this->fail('The v0.12.1 target accepted the current persisted installations.');
        } catch (ModuleLifecycleException $exception) {
            $this->assertSame(
                'target_contract_unrepresentable',
                $exception->reason,
            );
        }
    }

    public function test_v0131_target_rejects_the_synchronized_post_v0131_installations_without_mutating_state(): void
    {
        $target = $this->v0131Release();
        $before = DB::table('module_plugins')->orderBy('id')->get();
        $beforeInstances = ModuleInstallation::query()
            ->orderBy('sub_core_key')
            ->orderBy('module_key')
            ->get()
            ->map->instanceKey()
            ->all();

        $this->assertSame([
            'apes-cic:cases',
            'apes-cic:recruitment',
            'apes-cic:tickets',
            'pet-care-clinic:consultations',
            'pet-care-clinic:pet-profiles',
            'pet-care-clinic:tickets',
            'shelter-rescue:cases',
            'shelter-rescue:pet-profiles',
            'shelter-rescue:tickets',
        ], $beforeInstances);
        $this->assertTrue(ModuleInstallation::query()->get()->every->enabled);

        try {
            app(ModuleRollbackCompatibilityChecker::class)->check($target);
            $this->fail('The v0.13.1 target accepted the synchronized seventh installation.');
        } catch (ModuleLifecycleException $exception) {
            $this->assertSame('target_contract_unrepresentable', $exception->reason);
        }

        $this->assertEquals(
            $before,
            DB::table('module_plugins')->orderBy('id')->get(),
        );

        $this->artisan('myapes:modules:rollback-check', [
            '--target-release' => $target,
        ])
            ->expectsOutputToContain(
                'Module rollback compatibility: failed (target_contract_unrepresentable)',
            )
            ->assertFailed();

        $this->assertEquals(
            $before,
            DB::table('module_plugins')->orderBy('id')->get(),
        );
        $this->assertSame(
            $beforeInstances,
            ModuleInstallation::query()
                ->orderBy('sub_core_key')
                ->orderBy('module_key')
                ->get()
                ->map->instanceKey()
                ->all(),
        );
        $this->assertTrue(ModuleInstallation::query()->get()->every->enabled);
    }

    public function test_v0140_target_rejects_the_disabled_eighth_installation_without_mutating_state(): void
    {
        $target = $this->v0140Release();
        $petCareTickets = ModuleInstallation::query()
            ->where('sub_core_key', 'pet-care-clinic')
            ->where('module_key', 'tickets')
            ->firstOrFail();
        $petCareTickets->forceFill([
            'enabled' => false,
            'disabled_at' => now(),
        ])->save();
        $before = DB::table('module_plugins')->orderBy('id')->get();

        try {
            app(ModuleRollbackCompatibilityChecker::class)->check($target);
            $this->fail('The v0.14.0 target accepted the persisted eighth installation.');
        } catch (ModuleLifecycleException $exception) {
            $this->assertSame('target_contract_unrepresentable', $exception->reason);
        }

        $this->assertEquals(
            $before,
            DB::table('module_plugins')->orderBy('id')->get(),
        );

        $this->artisan('myapes:modules:rollback-check', [
            '--target-release' => $target,
        ])
            ->expectsOutputToContain(
                'Module rollback compatibility: failed (target_contract_unrepresentable)',
            )
            ->assertFailed();

        $this->assertEquals(
            $before,
            DB::table('module_plugins')->orderBy('id')->get(),
        );
        $this->assertFalse($petCareTickets->refresh()->enabled);
        $this->assertNotNull($petCareTickets->disabled_at);
    }

    public function test_malformed_or_unsafe_runtime_manifests_fail_closed(): void
    {
        $target = $this->temporaryRelease();
        file_put_contents(
            $target.'/resources/data/module-runtime-contract.json',
            json_encode([
                'schema_version' => 1,
                'application_version' => '0.11.0',
                'shipped_instances' => ['../unsafe'],
            ], JSON_THROW_ON_ERROR),
        );

        try {
            app(ModuleRollbackCompatibilityChecker::class)->check($target);
            $this->fail('An unsafe target capability manifest was accepted.');
        } catch (ModuleLifecycleException $exception) {
            $this->assertSame('target_manifest_invalid', $exception->reason);
        }
    }

    public function test_manifest_instances_must_reference_declared_contract_keys(): void
    {
        $target = $this->temporaryRelease();
        file_put_contents(
            $target.'/resources/data/module-runtime-contract.json',
            json_encode([
                'schema_version' => 1,
                'application_version' => '0.11.0',
                'sub_cores' => [
                    'apes-cic',
                    'pet-care-clinic',
                    'shelter-rescue',
                ],
                'module_types' => [
                    'cases',
                    'consultations',
                    'pet-profiles',
                    'tickets',
                ],
                'shipped_instances' => [
                    'apes-cic:tickets',
                    'pet-care-clinic:consultations',
                    'pet-care-clinic:pet-profiles',
                    'rogue-core:tickets',
                    'shelter-rescue:cases',
                    'shelter-rescue:pet-profiles',
                ],
                'legacy_visible_instances' => [
                    'apes-cic:tickets',
                    'pet-care-clinic:consultations',
                    'pet-care-clinic:pet-profiles',
                    'shelter-rescue:cases',
                    'shelter-rescue:pet-profiles',
                ],
            ], JSON_THROW_ON_ERROR),
        );

        try {
            app(ModuleRollbackCompatibilityChecker::class)->check($target);
            $this->fail('An undeclared manifest instance was accepted.');
        } catch (ModuleLifecycleException $exception) {
            $this->assertSame('target_manifest_invalid', $exception->reason);
        }
    }

    public function test_manifest_version_must_match_the_target_release_version(): void
    {
        $target = $this->temporaryRelease();
        copy(
            resource_path('data/module-runtime-contract.json'),
            $target.'/resources/data/module-runtime-contract.json',
        );
        file_put_contents($target.'/VERSION', "0.8.9\n");

        try {
            app(ModuleRollbackCompatibilityChecker::class)->check($target);
            $this->fail('A capability manifest with a mismatched release version was accepted.');
        } catch (ModuleLifecycleException $exception) {
            $this->assertSame('target_manifest_invalid', $exception->reason);
        }
    }

    public function test_manifest_object_key_order_does_not_change_its_meaning(): void
    {
        $target = $this->temporaryRelease();
        $manifest = json_decode(
            (string) file_get_contents(
                resource_path('data/module-runtime-contract.json'),
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame('0.42.6', $manifest['application_version']);
        file_put_contents(
            $target.'/resources/data/module-runtime-contract.json',
            json_encode([
                'application_version' => $manifest['application_version'],
                'schema_version' => $manifest['schema_version'],
                'module_types' => $manifest['module_types'],
                'sub_cores' => $manifest['sub_cores'],
                'modules' => $manifest['modules'] ?? $manifest['sub_cores'],
                'plugins' => $manifest['plugins'] ?? $manifest['module_types'],
                'legacy_visible_instances' => $manifest['legacy_visible_instances'],
                'shipped_instances' => $manifest['shipped_instances'],
            ], JSON_THROW_ON_ERROR),
        );
        copy(base_path('VERSION'), $target.'/VERSION');

        $this->assertSame(
            'manifest',
            app(ModuleRollbackCompatibilityChecker::class)
                ->check($target)['contract'],
        );
    }

    public function test_rollback_command_is_read_only_and_reports_only_contract_counts(): void
    {
        $before = ModuleInstallation::query()->orderBy('id')->get();

        $this->artisan('myapes:modules:rollback-check', [
            '--target-release' => base_path(),
        ])
            ->expectsOutputToContain(
                'Module rollback compatibility: ok (manifest, 9 installations)',
            )
            ->assertSuccessful();

        $this->assertEquals(
            $before,
            ModuleInstallation::query()->orderBy('id')->get(),
        );
    }

    private function temporaryRelease(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'myapes-module-rollback-'.Str::uuid();
        mkdir($path.'/resources/data', 0700, true);
        $this->temporaryDirectories[] = $path;

        return $path;
    }

    private function v0121Release(): string
    {
        $path = $this->temporaryRelease();
        file_put_contents($path.'/VERSION', "0.12.1\n");
        file_put_contents(
            $path.'/resources/data/module-runtime-contract.json',
            json_encode([
                'schema_version' => 1,
                'application_version' => '0.12.1',
                'sub_cores' => ['apes-cic', 'pet-care-clinic', 'shelter-rescue'],
                'module_types' => ['cases', 'consultations', 'pet-profiles', 'tickets'],
                'shipped_instances' => [
                    'apes-cic:tickets',
                    'pet-care-clinic:consultations',
                    'pet-care-clinic:pet-profiles',
                    'shelter-rescue:cases',
                    'shelter-rescue:pet-profiles',
                ],
                'legacy_visible_instances' => [
                    'apes-cic:tickets',
                    'pet-care-clinic:consultations',
                    'pet-care-clinic:pet-profiles',
                    'shelter-rescue:cases',
                    'shelter-rescue:pet-profiles',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        return $path;
    }

    private function v0131Release(): string
    {
        $path = $this->temporaryRelease();
        file_put_contents($path.'/VERSION', "0.13.1\n");
        file_put_contents(
            $path.'/resources/data/module-runtime-contract.json',
            json_encode([
                'schema_version' => 1,
                'application_version' => '0.13.1',
                'sub_cores' => ['apes-cic', 'pet-care-clinic', 'shelter-rescue'],
                'module_types' => ['cases', 'consultations', 'pet-profiles', 'tickets'],
                'shipped_instances' => [
                    'apes-cic:cases',
                    'apes-cic:tickets',
                    'pet-care-clinic:consultations',
                    'pet-care-clinic:pet-profiles',
                    'shelter-rescue:cases',
                    'shelter-rescue:pet-profiles',
                ],
                'legacy_visible_instances' => [
                    'apes-cic:tickets',
                    'pet-care-clinic:consultations',
                    'pet-care-clinic:pet-profiles',
                    'shelter-rescue:cases',
                    'shelter-rescue:pet-profiles',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        return $path;
    }

    private function v0140Release(): string
    {
        $path = $this->temporaryRelease();
        file_put_contents($path.'/VERSION', "0.14.0\n");
        file_put_contents(
            $path.'/resources/data/module-runtime-contract.json',
            json_encode([
                'schema_version' => 1,
                'application_version' => '0.14.0',
                'sub_cores' => ['apes-cic', 'pet-care-clinic', 'shelter-rescue'],
                'module_types' => ['cases', 'consultations', 'pet-profiles', 'tickets'],
                'shipped_instances' => [
                    'apes-cic:cases',
                    'apes-cic:tickets',
                    'pet-care-clinic:consultations',
                    'pet-care-clinic:pet-profiles',
                    'shelter-rescue:cases',
                    'shelter-rescue:pet-profiles',
                    'shelter-rescue:tickets',
                ],
                'legacy_visible_instances' => [
                    'apes-cic:tickets',
                    'pet-care-clinic:consultations',
                    'pet-care-clinic:pet-profiles',
                    'shelter-rescue:cases',
                    'shelter-rescue:pet-profiles',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        return $path;
    }
}
