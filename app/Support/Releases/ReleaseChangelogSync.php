<?php

namespace App\Support\Releases;

use App\Models\Release;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Deterministic transactional sync from changelog/releases/*.json into releases (#704).
 */
class ReleaseChangelogSync
{
    public function __construct(
        private readonly ReleaseEntryValidator $validator,
    ) {}

    public function directory(): string
    {
        $configured = config('releases.changelog_path', config('myapes.changelog.path'));

        return is_string($configured) && $configured !== ''
            ? $configured
            : base_path('changelog/releases');
    }

    /**
     * @return list<string>
     */
    public function entryPaths(): array
    {
        $dir = $this->directory();
        if (! is_dir($dir)) {
            return [];
        }

        $paths = File::glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [];
        $paths = array_values(array_filter(
            $paths,
            static fn (string $path): bool => ! str_starts_with(basename($path), '_'),
        ));

        usort($paths, function (string $a, string $b): int {
            return version_compare(
                $this->versionFromBasename(basename($a, '.json')),
                $this->versionFromBasename(basename($b, '.json')),
            );
        });

        return $paths;
    }

    /**
     * Upsert every file-backed release. Current = VERSION, not highest future.
     *
     * @return int Number of rows upserted
     */
    public function sync(?string $currentVersion = null): int
    {
        $paths = $this->entryPaths();
        if ($paths === []) {
            throw new RuntimeException('No changelog/releases/*.json entries found.');
        }

        $currentVersion = $currentVersion !== null
            ? ltrim(trim($currentVersion), 'vV')
            : trim((string) file_get_contents(base_path('VERSION')));

        $payloads = [];
        $seenVersions = [];
        $seenSlugs = [];

        foreach ($paths as $path) {
            $payload = $this->validator->parseFile($path);
            $version = (string) $payload['version'];
            $slug = is_string($payload['slug'] ?? null) && $payload['slug'] !== ''
                ? $payload['slug']
                : Release::slugFromVersion($version);

            if (isset($seenVersions[$version])) {
                throw new RuntimeException("Duplicate version [{$version}] in changelog/releases.");
            }
            if (isset($seenSlugs[$slug])) {
                throw new RuntimeException("Duplicate slug [{$slug}] in changelog/releases.");
            }

            $seenVersions[$version] = true;
            $seenSlugs[$slug] = true;
            $payloads[] = [
                'path' => $path,
                'payload' => $payload,
                'slug' => $slug,
                'checksum' => hash_file('sha256', $path) ?: null,
            ];
        }

        if (! isset($seenVersions[$currentVersion])) {
            throw new RuntimeException(
                "VERSION [{$currentVersion}] has no matching changelog/releases file; current cannot be set.",
            );
        }

        return (int) DB::transaction(function () use ($payloads, $currentVersion): int {
            $previousVersion = null;
            $upserted = 0;
            $touchedIds = [];

            foreach ($payloads as $entry) {
                /** @var array<string, mixed> $payload */
                $payload = $entry['payload'];
                $version = (string) $payload['version'];
                $slug = $entry['slug'];

                $existingBySlug = Release::query()->where('slug', $slug)->first();
                if ($existingBySlug !== null && $existingBySlug->version !== $version) {
                    throw new RuntimeException(
                        "Slug [{$slug}] already belongs to {$existingBySlug->version}.",
                    );
                }

                $attributes = [
                    'version' => $version,
                    'slug' => $slug,
                    'previous_version' => is_string($payload['previous_version'] ?? null)
                        ? $payload['previous_version']
                        : $previousVersion,
                    'released_at' => $payload['date'],
                    'channel' => $payload['channel'],
                    'type' => $payload['type'],
                    'title' => $payload['title'],
                    'is_current' => false,
                    'is_published' => true,
                    'summary' => $payload['summary'],
                    'changes' => $payload['changes'],
                    'affected_areas' => $payload['affected_areas'],
                    'categories' => $payload['categories'],
                    'audiences' => $payload['audiences'],
                    'version_rationale' => $payload['version_rationale'],
                    'validation' => $payload['validation'],
                    'known_limitations' => $payload['known_limitations'],
                    'rollback' => $payload['rollback'],
                    'provenance' => $payload['provenance'],
                    'references' => $payload['references'],
                    'source_checksum' => $entry['checksum'],
                    'source_path' => 'changelog/releases/'.basename($entry['path']),
                ];

                $row = Release::query()->updateOrCreate(['version' => $version], $attributes);
                $touchedIds[] = $row->id;
                $previousVersion = $version;
                $upserted++;
            }

            // Fail closed on missing historical files: prune only when every
            // previously published row is still represented by a file.
            $orphanCount = Release::query()->whereNotIn('id', $touchedIds)->count();
            if ($orphanCount > 0) {
                throw new RuntimeException(
                    "Sync refused: {$orphanCount} database release(s) have no matching file (history must not be pruned silently).",
                );
            }

            Release::query()->update(['is_current' => false]);
            Release::query()->where('version', $currentVersion)->update(['is_current' => true]);

            return $upserted;
        });
    }

    private function versionFromBasename(string $basename): string
    {
        return ltrim($basename, 'vV');
    }
}
