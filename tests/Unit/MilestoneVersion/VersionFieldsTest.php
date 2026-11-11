<?php

namespace Tests\Unit\MilestoneVersion;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Scripts\MilestoneVersion\SemVer;
use Scripts\MilestoneVersion\VersionFields;

class VersionFieldsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/version-fields-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/config', recursive: true);
        mkdir($this->root.'/notes');
        file_put_contents($this->root.'/VERSION', "0.4.7\n");
        file_put_contents($this->root.'/CloudronManifest.json', "{\n    \"id\": \"uk.org.apes.easm\",\n    \"version\": \"0.0.0\",\n    \"manifestVersion\": 2\n}\n");
        file_put_contents($this->root.'/config/app.php', "<?php\nreturn ['version' => env('APP_VERSION', '0.4.7')];\n");
        file_put_contents($this->root.'/notes/_template.json', "{\n  \"version\": \"vX.Y.Z\",\n  \"published\": false\n}\n");
    }

    protected function tearDown(): void
    {
        foreach (['VERSION', 'CloudronManifest.json', 'config/app.php', 'notes/_template.json', 'notes/v1.3.0.json'] as $file) {
            if (is_file($this->root.'/'.$file)) {
                unlink($this->root.'/'.$file);
            }
        }
        rmdir($this->root.'/config');
        rmdir($this->root.'/notes');
        rmdir($this->root);
    }

    private function fields(): VersionFields
    {
        return new VersionFields($this->root, [
            'fields' => [
                ['file' => 'VERSION', 'type' => 'plain'],
                ['file' => 'CloudronManifest.json', 'type' => 'json', 'key' => 'version'],
                ['file' => 'config/app.php', 'type' => 'regex', 'pattern' => "env\\('APP_VERSION', '([^']*)'\\)"],
            ],
            'releaseNote' => ['template' => 'notes/_template.json', 'path' => 'notes/{tag}.json'],
        ]);
    }

    public function test_bump_sets_every_field_to_the_same_version(): void
    {
        $version = SemVer::parse('v1.3.0');
        $changes = $this->fields()->bump($version);

        $this->assertSame("1.3.0\n", (string) file_get_contents($this->root.'/VERSION'));
        $this->assertStringContainsString('"version": "1.3.0"', (string) file_get_contents($this->root.'/CloudronManifest.json'));
        $this->assertStringContainsString('"manifestVersion": 2', (string) file_get_contents($this->root.'/CloudronManifest.json'));
        $this->assertStringContainsString("env('APP_VERSION', '1.3.0')", (string) file_get_contents($this->root.'/config/app.php'));
        $this->assertStringContainsString('"version": "v1.3.0"', (string) file_get_contents($this->root.'/notes/v1.3.0.json'));
        $this->assertSame(['VERSION', 'CloudronManifest.json', 'config/app.php', 'notes/v1.3.0.json'], array_column($changes, 'file'));
        $this->assertSame([], $this->fields()->mismatches($version));
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->fields()->bump(SemVer::parse('v1.3.0'), dryRun: true);

        $this->assertSame('0.4.7', trim((string) file_get_contents($this->root.'/VERSION')));
        $this->assertFileDoesNotExist($this->root.'/notes/v1.3.0.json');
    }

    public function test_mismatches_name_each_field_that_is_out_of_step(): void
    {
        $problems = $this->fields()->mismatches(SemVer::parse('v1.3.0'));

        $this->assertCount(3, $problems);
        $this->assertStringContainsString('CloudronManifest.json holds "0.0.0", not 1.3.0', implode("\n", $problems));
    }

    public function test_replace_all_updates_repeated_footer_versions_but_not_excluded_history(): void
    {
        mkdir($this->root.'/public/changelog', recursive: true);
        file_put_contents($this->root.'/VERSION', "v3.2.2b\n");
        file_put_contents($this->root.'/public/index.html', '<footer>Version v3.2.2b</footer>');
        file_put_contents($this->root.'/public/changelog/index.html', '<h2>v3.2.2b</h2>');
        $fields = new VersionFields($this->root, ['fields' => [
            ['file' => 'VERSION', 'type' => 'plain'],
            ['type' => 'replace-all', 'dir' => 'public', 'pattern' => '*.html', 'format' => 'tag', 'exclude' => ['public/changelog/index.html']],
        ]]);

        try {
            $changes = $fields->bump(SemVer::parse('v3.2.3'));

            $this->assertSame("3.2.3\n", (string) file_get_contents($this->root.'/VERSION'));
            $this->assertSame('<footer>Version v3.2.3</footer>', (string) file_get_contents($this->root.'/public/index.html'));
            $this->assertSame('<h2>v3.2.2b</h2>', (string) file_get_contents($this->root.'/public/changelog/index.html'));
            $this->assertContains('public/index.html', array_column($changes, 'file'));
            $this->assertSame([], $fields->mismatches(SemVer::parse('v3.2.3')));

            $fields->bump(SemVer::parse('v3.2.4'));

            $this->assertSame('<footer>Version v3.2.4</footer>', (string) file_get_contents($this->root.'/public/index.html'));
        } finally {
            unlink($this->root.'/public/index.html');
            unlink($this->root.'/public/changelog/index.html');
            rmdir($this->root.'/public/changelog');
            rmdir($this->root.'/public');
        }
    }

    public function test_a_missing_field_file_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);

        (new VersionFields($this->root, ['fields' => [['file' => 'package.json', 'type' => 'json', 'key' => 'version']]]))->bump(SemVer::parse('v1.3.0'));
    }
}
