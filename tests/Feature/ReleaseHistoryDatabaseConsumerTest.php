<?php

namespace Tests\Feature;

use App\Models\Release;
use App\Support\ReleaseHistoryRepository;
use App\Support\Releases\ReleaseChangelogSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReleaseHistoryDatabaseConsumerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function hub_and_repository_read_projected_rows_when_database_mode_is_on(): void
    {
        config(['myapes.changelog.use_database' => true]);

        $sync = $this->app->make(ReleaseChangelogSync::class);
        $count = $sync->sync();
        $this->assertGreaterThanOrEqual(252, $count);
        $this->assertSame(1, Release::query()->where('is_current', true)->count());

        $repository = $this->app->make(ReleaseHistoryRepository::class);
        $this->assertTrue($repository->usesDatabaseProjection());
        $this->assertSame(trim((string) file_get_contents(base_path('VERSION'))), $repository->version());
        $this->assertSame($repository->version(), $repository->current()['version']);

        $response = $this->get('/change-log');
        $response
            ->assertOk()
            ->assertSeeText('Current version v'.$repository->version())
            ->assertSee('href="#release-v'.str_replace('.', '-', $repository->version()).'"', false)
            ->assertSee('id="release-v'.str_replace('.', '-', $repository->version()).'"', false)
            ->assertSee('data-current="true"', false);
    }

    #[Test]
    public function healthz_still_reports_version_file_not_highest_future_row(): void
    {
        config(['myapes.changelog.use_database' => true]);
        $this->app->make(ReleaseChangelogSync::class)->sync();

        $response = $this->getJson('/healthz');
        $response
            ->assertOk()
            ->assertJsonPath('version', trim((string) file_get_contents(base_path('VERSION'))));
    }
}
