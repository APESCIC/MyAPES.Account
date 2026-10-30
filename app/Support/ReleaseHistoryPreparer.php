<?php

namespace App\Support;

use JsonException;
use RuntimeException;

class ReleaseHistoryPreparer
{
    /**
     * @var list<string>
     */
    private const VERSION_TYPES = ['major', 'minor', 'patch'];

    /**
     * @var list<string>
     */
    private const TEST_FILES = [
        'tests/Feature/HealthAndThemeTest.php',
        'tests/Feature/ModuleRollbackCompatibilityTest.php',
        'tests/Feature/ReleaseHistoryCommandTest.php',
        'tests/Feature/ChangeLogPageTest.php',
    ];

    public function __construct(
        private readonly ReleaseHistoryRepository $repository,
        private readonly string $rootPath = '',
    ) {}

    /**
     * @return array{
     *     previous_version: string,
     *     next_version: string,
     *     stub_record: array<string, mixed>,
     *     files: list<string>,
     * }
     */
    public function plan(
        string $type,
        string $title,
        string $channel,
        string $date,
        ?int $issueNumber,
        ?int $pullRequestNumber,
    ): array {
        $this->assertVersionType($type);
        $this->assertNonEmptyTitle($title);

        $versionPath = $this->path('VERSION');
        $releasesPath = $this->path('resources/data/releases.json');

        $previousVersion = $this->repository->readVersionFile($versionPath);
        $releases = $this->repository->readReleaseFile($releasesPath);
        $nextVersion = $this->bumpVersion($previousVersion, $type);

        if (($releases[0]['version'] ?? null) === $nextVersion) {
            throw new RuntimeException("Release history head is already at v{$nextVersion}; refusing to double-bump.");
        }

        if ($this->containsScaffoldPlaceholder($releases[0] ?? [])) {
            throw new RuntimeException('The current release head still contains TODO: Replace placeholders; complete or revert it before preparing another release.');
        }

        $stubRecord = $this->buildStubRecord(
            $nextVersion,
            $type,
            $title,
            $channel,
            $date,
            $issueNumber,
            $pullRequestNumber,
        );

        return [
            'previous_version' => $previousVersion,
            'next_version' => $nextVersion,
            'stub_record' => $stubRecord,
            'files' => [
                'VERSION',
                'resources/data/releases.json',
                'resources/data/module-runtime-contract.json',
                ...self::TEST_FILES,
            ],
        ];
    }

    /**
     * @return array{
     *     previous_version: string,
     *     next_version: string,
     *     stub_record: array<string, mixed>,
     *     files: list<string>,
     * }
     */
    public function apply(
        string $type,
        string $title,
        string $channel,
        string $date,
        ?int $issueNumber,
        ?int $pullRequestNumber,
    ): array {
        $plan = $this->plan($type, $title, $channel, $date, $issueNumber, $pullRequestNumber);

        $releasesPath = $this->path('resources/data/releases.json');
        $releases = $this->repository->readReleaseFile($releasesPath);
        array_unshift($releases, $plan['stub_record']);

        $this->writeVersion($plan['next_version']);
        $this->writeReleases($releases);
        $this->writeManifestVersion($plan['next_version']);
        $this->patchTestFiles($plan['previous_version'], $plan['next_version'], $date);

        return $plan;
    }

    private function root(): string
    {
        return $this->rootPath !== '' ? $this->rootPath : base_path();
    }

    private function path(string $relativePath): string
    {
        return $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function assertVersionType(string $type): void
    {
        if (! in_array($type, self::VERSION_TYPES, true)) {
            throw new RuntimeException('The --type option must be one of: major, minor, patch.');
        }
    }

    private function assertNonEmptyTitle(string $title): void
    {
        if (trim($title) === '') {
            throw new RuntimeException('The --title option is required.');
        }
    }

    private function bumpVersion(string $current, string $type): string
    {
        if (! preg_match('/\A(\d+)\.(\d+)\.(\d+)\z/', trim($current), $matches)) {
            throw new RuntimeException("Unable to bump invalid VERSION [{$current}].");
        }

        $major = (int) $matches[1];
        $minor = (int) $matches[2];
        $patch = (int) $matches[3];

        return match ($type) {
            'major' => ($major + 1).'.0.0',
            'minor' => "{$major}.".($minor + 1).'.0',
            'patch' => "{$major}.{$minor}.".($patch + 1),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStubRecord(
        string $version,
        string $type,
        string $title,
        string $channel,
        string $date,
        ?int $issueNumber,
        ?int $pullRequestNumber,
    ): array {
        return [
            'version' => $version,
            'date' => $date,
            'channel' => $channel,
            'type' => $type,
            'title' => trim($title),
            'summary' => 'TODO: Replace with a security-safe public summary.',
            'changes' => [
                'TODO: Replace with one or more reviewed change bullets.',
            ],
            'affected_areas' => [
                'TODO: Replace with affected product areas.',
            ],
            'categories' => $this->defaultCategories($type),
            'audiences' => ['public-facing', 'internal-only'],
            'version_rationale' => 'TODO: Replace with the semver rationale for this release.',
            'validation' => [
                'TODO: Replace with the validation performed before merge.',
            ],
            'known_limitations' => [
                'TODO: Replace with known limitations or state that none apply.',
            ],
            'rollback' => 'TODO: Replace with rollback guidance for this release.',
            'provenance' => 'TODO: Replace with issue and pull request provenance.',
            'references' => $this->buildReferences($issueNumber, $pullRequestNumber),
        ];
    }

    /**
     * @return list<string>
     */
    private function defaultCategories(string $type): array
    {
        return match ($type) {
            'major' => ['changed'],
            'minor' => ['added'],
            'patch' => ['fixed'],
        };
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function buildReferences(?int $issueNumber, ?int $pullRequestNumber): array
    {
        $references = [];

        if ($issueNumber !== null) {
            $references[] = [
                'label' => "Issue #{$issueNumber}",
                'url' => "https://github.com/APESCIC/MyAPES-Account/issues/{$issueNumber}",
            ];
        }

        if ($pullRequestNumber !== null) {
            $references[] = [
                'label' => "Pull request #{$pullRequestNumber}",
                'url' => "https://github.com/APESCIC/MyAPES-Account/pull/{$pullRequestNumber}",
            ];
        }

        if ($references === []) {
            $references[] = [
                'label' => 'TODO: Issue reference',
                'url' => 'https://github.com/APESCIC/MyAPES-Account/issues/1',
            ];
        }

        return $references;
    }

    private function writeVersion(string $version): void
    {
        $path = $this->path('VERSION');

        if (file_put_contents($path, $version.PHP_EOL) === false) {
            throw new RuntimeException("Unable to write version file [{$path}].");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $releases
     */
    private function writeReleases(array $releases): void
    {
        $path = $this->path('resources/data/releases.json');

        try {
            $encoded = json_encode(
                $releases,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode release history.', previous: $exception);
        }

        $encoded = str_replace('    ', '  ', $encoded).PHP_EOL;

        if (file_put_contents($path, $encoded) === false) {
            throw new RuntimeException("Unable to write release file [{$path}].");
        }
    }

    private function writeManifestVersion(string $version): void
    {
        $path = $this->path('resources/data/module-runtime-contract.json');

        if (! is_file($path)) {
            throw new RuntimeException("Unable to read module runtime manifest [{$path}].");
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to read module runtime manifest [{$path}].");
        }

        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Module runtime manifest is not valid JSON.', previous: $exception);
        }

        if (! is_array($manifest)) {
            throw new RuntimeException('Module runtime manifest must be a JSON object.');
        }

        $manifest['application_version'] = $version;

        try {
            $encoded = json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode module runtime manifest.', previous: $exception);
        }

        $encoded = str_replace('    ', '  ', $encoded).PHP_EOL;

        if (file_put_contents($path, $encoded) === false) {
            throw new RuntimeException("Unable to write module runtime manifest [{$path}].");
        }
    }

    private function patchTestFiles(string $previousVersion, string $nextVersion, string $date): void
    {
        foreach (self::TEST_FILES as $relativePath) {
            $path = $this->path($relativePath);

            if (! is_file($path)) {
                throw new RuntimeException("Unable to read test file [{$path}].");
            }

            $content = file_get_contents($path);

            if (! is_string($content)) {
                throw new RuntimeException("Unable to read test file [{$path}].");
            }

            $updated = $this->patchTestFileContent(
                $content,
                $previousVersion,
                $nextVersion,
                $date,
                $relativePath,
            );

            if (file_put_contents($path, $updated) === false) {
                throw new RuntimeException("Unable to write test file [{$path}].");
            }
        }
    }

    private function patchTestFileContent(
        string $content,
        string $previousVersion,
        string $nextVersion,
        string $date,
        string $relativePath,
    ): string {
        $content = preg_replace(
            '/\[\''.preg_quote($previousVersion, '/').'\',/',
            "['{$nextVersion}', '{$previousVersion}',",
            $content,
            1,
        ) ?? $content;

        $replacements = [
            "'version' => '{$previousVersion}'" => "'version' => '{$nextVersion}'",
            "assertSame('{$previousVersion}', \$repository->version())" => "assertSame('{$nextVersion}', \$repository->version())",
            "assertSame('{$previousVersion}', \$repository->current()['version'])" => "assertSame('{$nextVersion}', \$repository->current()['version'])",
            "assertSame('{$previousVersion}', \$manifest['application_version'])" => "assertSame('{$nextVersion}', \$manifest['application_version'])",
            "assertSame('{$previousVersion}', \$result['target_version'])" => "assertSame('{$nextVersion}', \$result['target_version'])",
            "Release history is valid at v{$previousVersion}" => "Release history is valid at v{$nextVersion}",
            "Current version v{$previousVersion}" => "Current version v{$nextVersion}",
            'aria-label="View the MyAPES Account change log for version v'.$previousVersion.'"' => 'aria-label="View the MyAPES Account change log for version v'.$nextVersion.'"',
            'href="#release-v'.str_replace('.', '-', $previousVersion).'"' => 'href="#release-v'.str_replace('.', '-', $nextVersion).'"',
            "assertSeeText('v{$previousVersion}')" => "assertSeeText('v{$nextVersion}')",
        ];

        foreach ($replacements as $search => $replace) {
            $content = str_replace($search, $replace, $content);
        }

        $content = preg_replace(
            '/assertSame\(\''.preg_quote($previousVersion, '/').'\', trim\(\(string\) file_get_contents\(base_path\(\'VERSION\'\)\)\)\)/',
            "assertSame('{$nextVersion}', trim((string) file_get_contents(base_path('VERSION'))))",
            $content,
        ) ?? $content;

        if ($relativePath === 'tests/Feature/ReleaseHistoryCommandTest.php') {
            $content = $this->patchReleaseHistoryDateAssertions($content, $date);
        }

        return $content;
    }

    private function patchReleaseHistoryDateAssertions(string $content, string $date): string
    {
        if (! preg_match_all(
            '/\$this->assertSame\(\'[^\']+\', \$releases\[(\d+)\]\[\'date\'\]\);/',
            $content,
            $matches,
        )) {
            return $content;
        }

        $indices = array_map('intval', $matches[1]);
        rsort($indices, SORT_NUMERIC);

        foreach ($indices as $index) {
            $content = str_replace(
                "\$releases[{$index}]['date']",
                '$releases['.($index + 1)."]['date']",
                $content,
            );
        }

        $insertion = "        \$this->assertSame('{$date}', \$releases[0]['date']);\n";
        $lowestShifted = ($indices === [] ? 0 : min($indices)) + 1;
        $anchor = "\$releases[{$lowestShifted}]['date']";
        $position = strpos($content, $anchor);

        if ($position === false) {
            return $content;
        }

        $lineStart = strrpos(substr($content, 0, $position), "\n");

        if ($lineStart === false) {
            return $insertion.$content;
        }

        return substr($content, 0, $lineStart + 1)
            .$insertion
            .substr($content, $lineStart + 1);
    }

    /**
     * @param  array<string, mixed>  $release
     */
    private function containsScaffoldPlaceholder(array $release): bool
    {
        $encoded = json_encode($release);

        if (! is_string($encoded)) {
            return true;
        }

        return str_contains($encoded, 'TODO: Replace');
    }
}
