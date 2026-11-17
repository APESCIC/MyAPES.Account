<?php

namespace Tests\Feature;

use App\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReleaseSchemaProjectionTest extends TestCase
{
    use RefreshDatabase;
    public function test_migration_creates_releases_table(): void
    {
        $this->assertTrue(Schema::hasTable('releases'));
        $this->assertTrue(Schema::hasColumns('releases', [
            'version',
            'slug',
            'previous_version',
            'released_at',
            'channel',
            'type',
            'title',
            'is_current',
            'is_published',
            'summary',
            'changes',
            'affected_areas',
            'categories',
            'audiences',
            'version_rationale',
            'validation',
            'known_limitations',
            'rollback',
            'provenance',
            'references',
            'source_checksum',
            'source_path',
        ]));
    }

    public function test_release_model_persists_projected_entry(): void
    {
        $entry = [
            'version' => '1.1.10',
            'date' => '2026-11-17',
            'channel' => 'stable',
            'type' => 'patch',
            'title' => 'Slug distinctness',
            'summary' => 'Projection test.',
            'changes' => ['Persisted a per-release row.'],
            'affected_areas' => ['Change log'],
            'categories' => ['added'],
            'audiences' => ['internal-only'],
            'version_rationale' => 'Patch: additive projection only.',
            'validation' => ['PHPUnit ReleaseSchemaProjectionTest.'],
            'known_limitations' => ['releases.json remains canonical.'],
            'rollback' => 'Drop projected rows or ignore the table.',
            'provenance' => 'Issue #705.',
            'references' => [
                [
                    'label' => 'Issue #705',
                    'url' => 'https://github.com/APESCIC/MyAPES-Account/issues/705',
                ],
            ],
            'is_published' => true,
        ];

        $release = Release::query()->create(Release::attributesFromEntry($entry));

        $this->assertDatabaseHas('releases', [
            'id' => $release->id,
            'version' => '1.1.10',
            'slug' => 'release-v1-1-10',
            'channel' => 'stable',
            'is_published' => true,
        ]);

        $release->refresh();
        $this->assertSame('2026-11-17', $release->released_at->format('Y-m-d'));
        $this->assertSame(['Persisted a per-release row.'], $release->changes);
    }

    public function test_changelog_path_config_defaults_to_changelog_releases(): void
    {
        $this->assertSame(
            base_path('changelog/releases'),
            config('releases.changelog_path'),
        );
    }
}