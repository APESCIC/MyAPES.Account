<?php

namespace Tests\Feature;

use App\Models\Release;
use App\Support\ReleaseHistoryRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReleaseHistoryConversionTest extends TestCase
{
    #[Test]
    public function converted_files_match_legacy_aggregate_field_by_field(): void
    {
        $repository = app(ReleaseHistoryRepository::class);
        $legacy = $repository->readReleaseFile(resource_path('data/releases.json'));

        $this->assertGreaterThanOrEqual(252, count($legacy));
        $this->assertSame('0.1.0', $legacy[array_key_last($legacy)]['version']);
        $this->assertSame(trim((string) file_get_contents(base_path('VERSION'))), $legacy[0]['version']);

        $dir = base_path('changelog/releases');
        $files = collect(File::files($dir))
            ->filter(static fn ($file): bool => str_starts_with($file->getFilename(), 'v')
                && str_ends_with($file->getFilename(), '.json'))
            ->values();

        $this->assertSame(count($legacy), $files->count());

        foreach ($legacy as $release) {
            $version = (string) $release['version'];
            $path = $dir.DIRECTORY_SEPARATOR.Release::filenameForVersion($version);
            $this->assertFileExists($path);

            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            foreach ($release as $field => $value) {
                $this->assertSame(
                    $value,
                    $payload[$field] ?? null,
                    "Field [{$field}] mismatched for v{$version}.",
                );
            }

            $this->assertSame(Release::slugFromVersion($version), $payload['slug'] ?? null);
            $this->assertSame(
                'release-v'.str_replace('.', '-', $version),
                'release-v'.str_replace('.', '-', $version),
            );
        }
    }

    #[Test]
    public function convert_rerun_is_a_noop(): void
    {
        $exit = Artisan::call('myapes:releases-convert');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('0 release file(s)', $output);
        $this->assertStringContainsString('already matched', $output);
    }

    #[Test]
    public function repository_loads_per_release_files_newest_first(): void
    {
        $releases = app(ReleaseHistoryRepository::class)->all();

        $this->assertSame(trim((string) file_get_contents(base_path('VERSION'))), $releases[0]['version']);
        $this->assertSame('0.1.0', $releases[array_key_last($releases)]['version']);
    }
}
