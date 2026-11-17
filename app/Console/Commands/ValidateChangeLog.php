<?php

namespace App\Console\Commands;

use App\Models\Release;
use App\Support\ReleaseHistoryRepository;
use App\Support\ReleaseHistoryValidator;
use App\Support\Releases\ReleaseEntryValidator;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class ValidateChangeLog extends Command
{
    protected $signature = 'myapes:changelog-validate
        {--base-ref= : Git commit or ref containing the previously published release history}';

    protected $description = 'Validate source-controlled release history and its append-only version contract';

    public function handle(
        ReleaseHistoryRepository $repository,
        ReleaseHistoryValidator $validator,
        ReleaseEntryValidator $entryValidator,
    ): int {
        try {
            $version = $repository->readVersionFile(base_path('VERSION'));
            $releases = $repository->readReleaseFile(resource_path('data/releases.json'));
            $manifestVersion = $this->readManifestVersion();
            $errors = $validator->validate($releases, $version, $manifestVersion);
            $errors = [
                ...$errors,
                ...$validator->validateVersionPinnedTests($version),
                ...$this->validatePerReleaseFiles($repository, $entryValidator, $releases, $version),
            ];

            $baseRef = trim((string) $this->option('base-ref'));

            if ($baseRef !== '') {
                $base = $this->loadBaseHistory($repository, $baseRef);

                if ($base === null) {
                    $this->components->info('Base release contract is absent; structural validation only.');
                } else {
                    $errors = [
                        ...$errors,
                        ...$validator->validateAppendOnly(
                            $releases,
                            $version,
                            $base['releases'],
                            $base['version'],
                            $manifestVersion,
                        ),
                    ];
                }
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $errors = array_values(array_unique($errors));

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $this->components->info("Release history is valid at v{$version}.");

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $aggregate
     * @return list<string>
     */
    private function validatePerReleaseFiles(
        ReleaseHistoryRepository $repository,
        ReleaseEntryValidator $entryValidator,
        array $aggregate,
        string $version,
    ): array {
        $dir = $repository->changelogDirectory();

        if (! is_dir($dir)) {
            return ['Per-release changelog directory is missing.'];
        }

        $errors = $entryValidator->validateDirectory($dir);
        $fromFiles = $repository->aggregateFromPerReleaseFiles();

        if (count($fromFiles) !== count($aggregate)) {
            $errors[] = sprintf(
                'Per-release file count (%d) does not match resources/data/releases.json (%d).',
                count($fromFiles),
                count($aggregate),
            );
        }

        foreach ($aggregate as $index => $release) {
            $fileRelease = $fromFiles[$index] ?? null;
            if (! is_array($fileRelease)) {
                continue;
            }

            foreach ($release as $field => $value) {
                if (($fileRelease[$field] ?? null) !== $value) {
                    $errors[] = "Aggregate and per-release file diverge at index {$index} field [{$field}].";
                    break;
                }
            }

            $releaseVersion = (string) ($release['version'] ?? '');
            $path = $dir.DIRECTORY_SEPARATOR.Release::filenameForVersion($releaseVersion);
            if (! is_file($path)) {
                $errors[] = "Missing per-release file for v{$releaseVersion}.";
            }
        }

        $currentPath = $dir.DIRECTORY_SEPARATOR.Release::filenameForVersion($version);
        if (! is_file($currentPath)) {
            $errors[] = "VERSION [{$version}] has no matching changelog/releases file.";
        }

        return $errors;
    }

    /**
     * @return array{version: string, releases: list<array<string, mixed>>}|null
     */
    private function loadBaseHistory(ReleaseHistoryRepository $repository, string $baseRef): ?array
    {
        $this->runGit(['rev-parse', '--verify', "{$baseRef}^{commit}"], "Unable to resolve base ref [{$baseRef}].");

        $hasVersion = $this->gitPathExists($baseRef, 'VERSION');
        $hasReleases = $this->gitPathExists($baseRef, 'resources/data/releases.json');

        if (! $hasVersion && ! $hasReleases) {
            return null;
        }

        if (! $hasVersion || ! $hasReleases) {
            throw new RuntimeException("Base ref [{$baseRef}] contains an incomplete release contract.");
        }

        $version = trim($this->runGit(['show', "{$baseRef}:VERSION"]));
        $releaseJson = $this->runGit(['show', "{$baseRef}:resources/data/releases.json"]);

        return [
            'version' => $version,
            'releases' => $repository->decodeReleaseJson($releaseJson),
        ];
    }

    private function gitPathExists(string $baseRef, string $path): bool
    {
        $process = new Process(['git', 'cat-file', '-e', "{$baseRef}:{$path}"], base_path());
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runGit(array $arguments, ?string $failureMessage = null): string
    {
        $process = new Process(['git', ...$arguments], base_path());
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException($failureMessage ?? 'Unable to read the base release history.');
        }

        return $process->getOutput();
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
            throw new RuntimeException('Module runtime manifest is missing application_version.');
        }

        return trim($manifest['application_version']);
    }
}
