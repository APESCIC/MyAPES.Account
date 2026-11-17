<?php

namespace Tests\Feature;

use App\Models\Release;
use App\Support\Releases\ReleaseChangelogSync;
use App\Support\Releases\ReleaseEntryValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReleaseChangelogSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/changelog-sync-'.uniqid('', true));
        File::ensureDirectoryExists($this->dir);
        config(['releases.changelog_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir)) {
            File::deleteDirectory($this->dir);
        }

        parent::tearDown();
    }

    #[Test]
    public function two_sync_runs_are_semantically_identical(): void
    {
        $this->writeReleaseFile('0.1.0', '2026-01-01');
        $this->writeReleaseFile('0.1.1', '2026-01-02');

        $sync = $this->app->make(ReleaseChangelogSync::class);
        $first = $sync->sync('0.1.1');
        $snapshot = Release::query()->orderBy('version')->get()->map->toArray()->all();

        $second = $sync->sync('0.1.1');
        $again = Release::query()->orderBy('version')->get()->map->toArray()->all();

        $this->assertSame(2, $first);
        $this->assertSame(2, $second);
        $this->assertSame($this->stripTimestamps($snapshot), $this->stripTimestamps($again));
        $this->assertTrue(Release::query()->where('version', '0.1.1')->value('is_current'));
        $this->assertFalse(Release::query()->where('version', '0.1.0')->value('is_current'));
    }

    #[Test]
    public function malformed_last_file_leaves_rows_untouched(): void
    {
        $this->writeReleaseFile('0.1.0', '2026-01-01');
        $this->writeReleaseFile('0.1.1', '2026-01-02');

        $sync = $this->app->make(ReleaseChangelogSync::class);
        $sync->sync('0.1.1');
        $before = Release::query()->orderBy('version')->get()->map->toArray()->all();

        File::put($this->dir.DIRECTORY_SEPARATOR.'v0.1.2.json', "{not-json\n");

        try {
            $sync->sync('0.1.1');
            $this->fail('Expected sync to fail on malformed JSON.');
        } catch (\InvalidArgumentException|\RuntimeException) {
            // expected
        }

        $after = Release::query()->orderBy('version')->get()->map->toArray()->all();
        $this->assertSame($this->stripTimestamps($before), $this->stripTimestamps($after));
        $this->assertSame(2, Release::query()->count());
    }

    #[Test]
    public function missing_historical_file_fails_without_pruning(): void
    {
        $this->writeReleaseFile('0.1.0', '2026-01-01');
        $this->writeReleaseFile('0.1.1', '2026-01-02');

        $sync = $this->app->make(ReleaseChangelogSync::class);
        $sync->sync('0.1.1');

        File::delete($this->dir.DIRECTORY_SEPARATOR.'v0.1.0.json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no matching file');
        $sync->sync('0.1.1');
    }

    #[Test]
    public function current_follows_version_not_highest_future_entry(): void
    {
        $this->writeReleaseFile('0.1.0', '2026-01-01');
        $this->writeReleaseFile('0.1.1', '2026-01-02');
        $this->writeReleaseFile('0.2.0', '2026-01-03');

        $sync = $this->app->make(ReleaseChangelogSync::class);
        $sync->sync('0.1.1');

        $this->assertTrue(Release::query()->where('version', '0.1.1')->value('is_current'));
        $this->assertFalse(Release::query()->where('version', '0.2.0')->value('is_current'));
    }

    #[Test]
    public function rollback_to_older_version_excludes_newer_only_current(): void
    {
        $this->writeReleaseFile('0.1.0', '2026-01-01');
        $this->writeReleaseFile('0.1.1', '2026-01-02');
        $this->writeReleaseFile('0.2.0', '2026-01-03');

        $sync = $this->app->make(ReleaseChangelogSync::class);
        $sync->sync('0.2.0');
        $this->assertTrue(Release::query()->where('version', '0.2.0')->value('is_current'));

        $sync->sync('0.1.1');
        $this->assertTrue(Release::query()->where('version', '0.1.1')->value('is_current'));
        $this->assertFalse(Release::query()->where('version', '0.2.0')->value('is_current'));
        $this->assertSame(3, Release::query()->count());
    }

    #[Test]
    public function dry_run_validates_without_writing(): void
    {
        $this->writeReleaseFile('0.1.0', '2026-01-01');

        $this->artisan('myapes:releases-sync', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, Release::query()->count());
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function stripTimestamps(array $rows): array
    {
        return array_map(static function (array $row): array {
            unset($row['created_at'], $row['updated_at']);

            return $row;
        }, $rows);
    }

    private function writeReleaseFile(string $version, string $date): void
    {
        $payload = [
            'version' => $version,
            'date' => $date,
            'channel' => 'stable',
            'type' => 'patch',
            'title' => "Release {$version}",
            'summary' => "Summary for {$version}.",
            'changes' => ["Change in {$version}."],
            'affected_areas' => ['Tests'],
            'categories' => ['fixed'],
            'audiences' => ['public-facing'],
            'version_rationale' => 'Patch.',
            'validation' => ['phpunit'],
            'known_limitations' => ['None.'],
            'rollback' => 'Redeploy previous.',
            'provenance' => 'Test.',
            'references' => [
                ['label' => 'Issue #0', 'url' => 'https://github.com/APESCIC/MyAPES-Account/issues/0'],
            ],
            'slug' => Release::slugFromVersion($version),
        ];

        $errors = (new ReleaseEntryValidator)->validatePayload($payload, Release::filenameForVersion($version));
        $this->assertSame([], $errors, implode('; ', $errors));

        File::put(
            $this->dir.DIRECTORY_SEPARATOR.Release::filenameForVersion($version),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }
}
