<?php

namespace Tests\Feature;

use App\Support\ReleaseHistoryPreparer;
use App\Support\ReleaseHistoryRepository;
use Tests\TestCase;

class PrepareChangeLogCommandTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'myapes-changelog-prepare-'.uniqid('', true);
        mkdir($this->tempRoot, 0777, true);
        mkdir($this->tempRoot.'/resources/data', 0777, true);
        mkdir($this->tempRoot.'/tests/Feature', 0777, true);

        file_put_contents($this->tempRoot.'/VERSION', "0.6.0\n");
        file_put_contents(
            $this->tempRoot.'/resources/data/releases.json',
            json_encode([
                [
                    'version' => '0.6.0',
                    'date' => '2026-07-27',
                    'channel' => 'stable',
                    'type' => 'minor',
                    'title' => 'Release 0.6.0',
                    'summary' => 'A security-safe public summary.',
                    'changes' => ['Implemented a reviewed capability.'],
                    'affected_areas' => ['MyAPES Account'],
                    'categories' => ['added'],
                    'audiences' => ['public-facing'],
                    'version_rationale' => 'A backward-compatible capability.',
                    'validation' => ['Automated tests passed.'],
                    'known_limitations' => ['No production deployment was performed.'],
                    'rollback' => 'Restore the previous reviewed release.',
                    'provenance' => 'Reconstructed from a merged pull request.',
                    'references' => [[
                        'label' => 'PR #1',
                        'url' => 'https://github.com/APESCIC/MyAPES-Account/pull/1',
                    ]],
                ],
                [
                    'version' => '0.5.0',
                    'date' => '2026-07-27',
                    'channel' => 'stable',
                    'type' => 'minor',
                    'title' => 'Release 0.5.0',
                    'summary' => 'A security-safe public summary.',
                    'changes' => ['Implemented a reviewed capability.'],
                    'affected_areas' => ['MyAPES Account'],
                    'categories' => ['added'],
                    'audiences' => ['public-facing'],
                    'version_rationale' => 'A backward-compatible capability.',
                    'validation' => ['Automated tests passed.'],
                    'known_limitations' => ['No production deployment was performed.'],
                    'rollback' => 'Restore the previous reviewed release.',
                    'provenance' => 'Reconstructed from a merged pull request.',
                    'references' => [[
                        'label' => 'PR #1',
                        'url' => 'https://github.com/APESCIC/MyAPES-Account/pull/1',
                    ]],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
        file_put_contents(
            $this->tempRoot.'/resources/data/module-runtime-contract.json',
            json_encode([
                'schema_version' => 1,
                'application_version' => '0.6.0',
                'sub_cores' => ['apes-cic'],
                'module_types' => ['cases'],
                'shipped_instances' => ['apes-cic:cases'],
                'legacy_visible_instances' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );

        foreach ([
            'HealthAndThemeTest.php' => "<?php\n\nnamespace Tests\\Feature;\n\nclass HealthAndThemeTest\n{\n    public function test_health(): void\n    {\n        \$this->assertExactJson(['version' => '0.6.0']);\n    }\n}\n",
            'ModuleRollbackCompatibilityTest.php' => "<?php\n\nnamespace Tests\\Feature;\n\nclass ModuleRollbackCompatibilityTest\n{\n    public function test_manifest(): void\n    {\n        \$this->assertSame('0.6.0', trim((string) file_get_contents(base_path('VERSION'))));\n        \$this->assertSame('0.6.0', \$manifest['application_version']);\n        \$this->assertSame('0.6.0', \$result['target_version']);\n    }\n}\n",
            'ReleaseHistoryCommandTest.php' => "<?php\n\nnamespace Tests\\Feature;\n\nclass ReleaseHistoryCommandTest\n{\n    public function test_sequence(): void\n    {\n        \$this->assertSame('0.6.0', \$repository->version());\n        \$this->assertSame('0.6.0', \$repository->current()['version']);\n        \$this->assertSame(['0.6.0', '0.5.0'], array_column(\$releases, 'version'));\n        \$this->expectsOutputToContain('Release history is valid at v0.6.0');\n    }\n}\n",
            'ChangeLogPageTest.php' => "<?php\n\nnamespace Tests\\Feature;\n\nclass ChangeLogPageTest\n{\n    public function test_page(): void\n    {\n        \$response->assertSeeText('Current version v0.6.0');\n        foreach (['0.6.0', '0.5.0'] as \$version) {\n            \$response->assertSeeText(\"v{\$version}\");\n        }\n        \$response->assertSee('aria-label=\"View the MyAPES Account change log for version v0.6.0\"', false);\n        \$response->assertSee('href=\"#release-v0-6-0\"', false);\n        \$response->assertSeeText('v0.6.0');\n    }\n}\n",
        ] as $filename => $contents) {
            file_put_contents($this->tempRoot.'/tests/Feature/'.$filename, $contents);
        }
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempRoot);

        parent::tearDown();
    }

    public function test_prepare_command_scaffolds_a_patch_release_and_syncs_files(): void
    {
        $preparer = $this->preparer();

        $plan = $preparer->apply(
            'patch',
            'Agent release metadata prepare command',
            'stable',
            '2026-08-20',
            67,
            null,
        );

        $this->assertSame('0.6.0', $plan['previous_version']);
        $this->assertSame('0.6.1', $plan['next_version']);
        $this->assertSame('0.6.1', trim((string) file_get_contents($this->tempRoot.'/VERSION')));

        $releases = json_decode(
            (string) file_get_contents($this->tempRoot.'/resources/data/releases.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $manifest = json_decode(
            (string) file_get_contents($this->tempRoot.'/resources/data/module-runtime-contract.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('0.6.1', $releases[0]['version']);
        $this->assertSame('Agent release metadata prepare command', $releases[0]['title']);
        $this->assertSame('patch', $releases[0]['type']);
        $this->assertSame('0.6.0', $releases[1]['version']);
        $this->assertSame('0.6.1', $manifest['application_version']);
        $this->assertSame(
            [[
                'label' => 'Issue #67',
                'url' => 'https://github.com/APESCIC/MyAPES-Account/issues/67',
            ]],
            $releases[0]['references'],
        );

        $healthTest = (string) file_get_contents($this->tempRoot.'/tests/Feature/HealthAndThemeTest.php');
        $sequenceTest = (string) file_get_contents($this->tempRoot.'/tests/Feature/ReleaseHistoryCommandTest.php');
        $changeLogTest = (string) file_get_contents($this->tempRoot.'/tests/Feature/ChangeLogPageTest.php');

        $this->assertStringContainsString("'version' => '0.6.1'", $healthTest);
        $this->assertStringContainsString("assertSame('0.6.1', \$repository->version())", $sequenceTest);
        $this->assertStringContainsString("['0.6.1', '0.6.0',", $sequenceTest);
        $this->assertStringContainsString('Current version v0.6.1', $changeLogTest);
        $this->assertStringContainsString("foreach (['0.6.1', '0.6.0', '0.5.0']", $changeLogTest);
    }

    public function test_prepare_command_computes_minor_and_major_versions(): void
    {
        $preparer = $this->preparer();

        $minor = $preparer->plan('minor', 'Minor release', 'stable', '2026-08-20', null, null);
        $this->assertSame('0.7.0', $minor['next_version']);
        $this->assertSame(['added'], $minor['stub_record']['categories']);

        file_put_contents($this->tempRoot.'/VERSION', "1.2.3\n");
        $major = $preparer->plan('major', 'Major release', 'stable', '2026-08-20', null, null);
        $this->assertSame('2.0.0', $major['next_version']);
        $this->assertSame(['changed'], $major['stub_record']['categories']);
    }

    public function test_prepare_command_dry_run_writes_nothing(): void
    {
        $beforeVersion = file_get_contents($this->tempRoot.'/VERSION');
        $beforeReleases = file_get_contents($this->tempRoot.'/resources/data/releases.json');
        $preparer = $this->preparer();

        $plan = $preparer->plan('patch', 'Dry run only', 'stable', '2026-08-20', 67, null);

        $this->assertSame('0.6.1', $plan['next_version']);
        $this->assertSame($beforeVersion, file_get_contents($this->tempRoot.'/VERSION'));
        $this->assertSame($beforeReleases, file_get_contents($this->tempRoot.'/resources/data/releases.json'));
    }

    public function test_artisan_prepare_command_supports_dry_run_against_the_repository(): void
    {
        $beforeVersion = trim((string) file_get_contents(base_path('VERSION')));

        $this->artisan('myapes:changelog-prepare', [
            '--type' => 'patch',
            '--title' => 'Dry run only',
            '--issue' => 67,
            '--pr' => 68,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Dry run only; no files were modified.')
            ->assertSuccessful();

        $this->assertSame($beforeVersion, trim((string) file_get_contents(base_path('VERSION'))));
    }

    public function test_artisan_prepare_command_requires_pull_request_number(): void
    {
        $this->artisan('myapes:changelog-prepare', [
            '--type' => 'patch',
            '--title' => 'Missing PR reference',
            '--issue' => 67,
        ])
            ->expectsOutputToContain('The --pr option is required')
            ->assertFailed();
    }

    public function test_prepare_command_requires_a_title(): void
    {
        $preparer = $this->preparer();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The --title option is required.');

        $preparer->plan('patch', '   ', 'stable', '2026-08-20', null, null);
    }

    public function test_prepare_command_refuses_a_double_bump(): void
    {
        $preparer = $this->preparer();
        $preparer->apply('patch', 'First release', 'stable', '2026-08-20', 67, null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('still contains TODO: Replace placeholders');

        $preparer->apply('patch', 'Second release', 'stable', '2026-08-20', 67, null);
    }

    public function test_prepare_command_builds_pull_request_references(): void
    {
        $preparer = $this->preparer();

        $plan = $preparer->plan('patch', 'Reference test', 'stable', '2026-08-20', 67, 68);

        $this->assertSame(
            [
                [
                    'label' => 'Issue #67',
                    'url' => 'https://github.com/APESCIC/MyAPES-Account/issues/67',
                ],
                [
                    'label' => 'Pull request #68',
                    'url' => 'https://github.com/APESCIC/MyAPES-Account/pull/68',
                ],
            ],
            $plan['stub_record']['references'],
        );
    }

    private function preparer(): ReleaseHistoryPreparer
    {
        return new ReleaseHistoryPreparer(app(ReleaseHistoryRepository::class), $this->tempRoot);
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
