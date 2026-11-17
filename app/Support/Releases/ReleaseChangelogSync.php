<?php

namespace App\Support\Releases;

use App\Models\Release;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Deterministic transactional sync from changelog/releases/*.json into releases (#704).
 */
class ReleaseChangelogSync
{
    public const LOCK_KEY = 'myapes.releases.sync';

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
     * Validate every file without writing. Throws on the first invalid entry.
     *
     * @return list<array{path: string, payload: array<string, mixed>, slug: string, checksum: ?string}>
     */
    public function validateAll(): array
    {
        $paths = $this->entryPaths();
        if ($paths === []) {
            throw new RuntimeException('No changelog/releases/*.json entries found.');
        }

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

        return $payloads;
    }

    /**
     * Upsert every file-backed release. Current = VERSION, not highest future.
     *
     * @return int Number of rows upserted
     */
    public function sync(?string $currentVersion = null): int
    {
        $lock = Cache::lock(self::LOCK_KEY, 120);

        if (! $lock->get()) {
            throw new RuntimeException('Another releases sync is already running.');
        }

        try {
            $payloads = $this->validateAll();

            $currentVersion = $currentVersion !== null
                ? ltrim(trim($currentVersion), 'vV')
                : trim((string) file_get_contents(base_path('VERSION')));

            $seenVersions = [];
            foreach ($payloads as $entry) {
                $seenVersions[(string) $entry['payload']['version']] = true;
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

                    if (! is_string($payload['date'] ?? null) || trim((string) $payload['date']) === '') {
                        throw new RuntimeException(
                            "Release [{$version}] is missing a release date; refusing to default to today.",
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

                // Fail closed on missing historical files (version <= current).
                // Newer-only rows (version > VERSION) are unpublished so rollback
                // can select the older current without inventing or deleting history (#711).
                $orphans = Release::query()->whereNotIn('id', $touchedIds)->get();
                foreach ($orphans as $orphan) {
                    if (version_compare((string) $orphan->version, $currentVersion, '<=')) {
                        throw new RuntimeException(
                            "Sync refused: database release [{$orphan->version}] has no matching file (history must not be pruned silently).",
                        );
                    }

                    $orphan->forceFill([
                        'is_published' => false,
                        'is_current' => false,
                    ])->save();
                }

                Release::query()->update(['is_current' => false]);
                Release::query()->where('version', $currentVersion)->update(['is_current' => true]);

                return $upserted;
            });
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function versionFromBasename(string $basename): string
    {
        return ltrim($basename, 'vV');
    }
}
