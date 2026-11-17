<?php

namespace App\Support;

use App\Models\Release;
use App\Support\Releases\ReleaseChangelogSync;
use App\Support\Releases\ReleaseEntryValidator;
use JsonException;
use RuntimeException;
use UnexpectedValueException;

class ReleaseHistoryRepository
{
    /**
     * @var list<array<string, mixed>>|null
     */
    private ?array $releases = null;

    private ?string $appVersion = null;

    public function __construct(
        private readonly ReleaseHistoryValidator $validator,
        private readonly ReleaseEntryValidator $entryValidator = new ReleaseEntryValidator,
    ) {}

    public function version(): string
    {
        return $this->appVersion ??= $this->readVersionFile(base_path('VERSION'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->releases !== null) {
            return $this->releases;
        }

        if ($this->usesDatabaseProjection()) {
            return $this->releases = $this->loadFromDatabase();
        }

        if ($this->hasPerReleaseFiles()) {
            return $this->releases = $this->loadFromPerReleaseFiles();
        }

        $releases = $this->readReleaseFile(resource_path('data/releases.json'));
        $errors = $this->validator->validate(
            $releases,
            $this->version(),
            $this->readManifestVersion(),
        );

        if ($errors !== []) {
            throw new UnexpectedValueException(implode(PHP_EOL, $errors));
        }

        return $this->releases = $releases;
    }

    /**
     * @return array<string, mixed>
     */
    public function current(): array
    {
        if ($this->usesDatabaseProjection()) {
            $current = Release::query()->published()->current()->first();

            if ($current === null) {
                throw new UnexpectedValueException(
                    'No current published release is projected; refuse stale or empty database history.',
                );
            }

            if ($current->version !== $this->version()) {
                throw new UnexpectedValueException(
                    "Projected current release [{$current->version}] does not match VERSION [{$this->version()}].",
                );
            }

            return $current->toLegacyArray();
        }

        return $this->all()[0];
    }

    public function usesDatabaseProjection(): bool
    {
        return (bool) config('myapes.changelog.use_database', false);
    }

    public function hasPerReleaseFiles(): bool
    {
        $dir = $this->changelogDirectory();

        if (! is_dir($dir)) {
            return false;
        }

        $paths = glob($dir.DIRECTORY_SEPARATOR.'v*.json') ?: [];

        return $paths !== [];
    }

    public function changelogDirectory(): string
    {
        $configured = config('releases.changelog_path', config('myapes.changelog.path'));

        return is_string($configured) && $configured !== ''
            ? $configured
            : base_path('changelog/releases');
    }

    public function readVersionFile(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException("Unable to read version file [{$path}].");
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to read version file [{$path}].");
        }

        return trim($contents);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function readReleaseFile(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Unable to read release file [{$path}].");
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to read release file [{$path}].");
        }

        return $this->decodeReleaseJson($contents);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function decodeReleaseJson(string $contents): array
    {
        try {
            $releases = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('Release history is not valid JSON.', previous: $exception);
        }

        if (! is_array($releases) || ! array_is_list($releases)) {
            throw new UnexpectedValueException('Release history must be a JSON list.');
        }

        foreach ($releases as $release) {
            if (! is_array($release)) {
                throw new UnexpectedValueException('Every release record must be a JSON object.');
            }
        }

        return $releases;
    }

    /**
     * Build the legacy aggregate (newest first) from per-release files.
     *
     * @return list<array<string, mixed>>
     */
    public function aggregateFromPerReleaseFiles(): array
    {
        return $this->loadFromPerReleaseFiles();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadFromDatabase(): array
    {
        $rows = Release::query()
            ->published()
            ->orderByDesc('released_at')
            ->orderByDesc('version')
            ->get();

        if ($rows->isEmpty()) {
            throw new UnexpectedValueException(
                'MYAPES_CHANGELOG_USE_DATABASE is enabled but no published releases are projected.',
            );
        }

        $releases = $rows->map(static fn (Release $release): array => $release->toLegacyArray())->all();

        // Soft-check VERSION is present; do not invent a current from the highest row.
        $versions = array_column($releases, 'version');
        if (! in_array($this->version(), $versions, true)) {
            throw new UnexpectedValueException(
                'VERSION ['.$this->version().'] is missing from the projected release history.',
            );
        }

        // Newest-first by semver for hub consumers that expect index 0 ≈ current when public.
        usort($releases, static function (array $a, array $b): int {
            return version_compare((string) $b['version'], (string) $a['version']);
        });

        return $releases;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadFromPerReleaseFiles(): array
    {
        $sync = new ReleaseChangelogSync($this->entryValidator);
        $paths = $sync->entryPaths();

        if ($paths === []) {
            throw new UnexpectedValueException('No changelog/releases/*.json entries found.');
        }

        $releases = [];

        foreach ($paths as $path) {
            $payload = $this->entryValidator->parseFile($path);
            $legacy = $payload;
            unset($legacy['slug'], $legacy['previous_version'], $legacy['is_current'], $legacy['is_published'], $legacy['source_checksum'], $legacy['source_path']);
            $releases[] = $legacy;
        }

        // Newest first.
        usort($releases, static function (array $a, array $b): int {
            return version_compare((string) $b['version'], (string) $a['version']);
        });

        $errors = $this->validator->validate(
            $releases,
            $this->version(),
            $this->readManifestVersion(),
        );

        if ($errors !== []) {
            throw new UnexpectedValueException(implode(PHP_EOL, $errors));
        }

        return $releases;
    }

    private function readManifestVersion(): ?string
    {
        $path = resource_path('data/module-runtime-contract.json');

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to read module runtime manifest [{$path}].");
        }

        $manifest = json_decode($contents, true);

        if (! is_array($manifest) || ! is_string($manifest['application_version'] ?? null)) {
            throw new UnexpectedValueException('Module runtime manifest is missing application_version.');
        }

        return trim($manifest['application_version']);
    }
}
