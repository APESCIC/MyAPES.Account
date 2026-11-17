<?php

namespace App\Console\Commands;

use App\Models\Release;
use App\Support\ReleaseHistoryRepository;
use App\Support\Releases\ReleaseEntryValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * One-time lossless split of resources/data/releases.json into per-file entries (#706).
 */
class ConvertReleaseHistoryCommand extends Command
{
    protected $signature = 'myapes:releases-convert
        {--force : Overwrite existing per-release files}
        {--dry-run : Report without writing files}
        {--write-aggregate : Rewrite resources/data/releases.json from per-file sources}';

    protected $description = 'Convert resources/data/releases.json into changelog/releases/vX.Y.Z.json (#706)';

    public function handle(ReleaseHistoryRepository $repository, ReleaseEntryValidator $validator): int
    {
        try {
            $releases = $repository->readReleaseFile(resource_path('data/releases.json'));
            $dir = base_path('changelog/releases');
            File::ensureDirectoryExists($dir);

            // Oldest first so previous_version chains read naturally on disk.
            $chronological = array_reverse($releases);
            $written = 0;
            $skipped = 0;
            $filePayloads = [];

            foreach ($chronological as $index => $release) {
                /** @var array<string, mixed> $release */
                $version = (string) $release['version'];
                $filename = Release::filenameForVersion($version);
                $path = $dir.DIRECTORY_SEPARATOR.$filename;

                $payload = $release;
                $payload['slug'] = Release::slugFromVersion($version);
                if ($index > 0) {
                    /** @var array<string, mixed> $older */
                    $older = $chronological[$index - 1];
                    $payload['previous_version'] = (string) $older['version'];
                }

                $errors = $validator->validatePayload($payload, $filename);
                if ($errors !== []) {
                    $this->components->error("{$filename}: ".implode('; ', $errors));

                    return self::FAILURE;
                }

                $encoded = json_encode(
                    $payload,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )."\n";

                $filePayloads[] = $payload;

                if (is_file($path) && ! $this->option('force')) {
                    $existingRaw = (string) file_get_contents($path);
                    $existing = json_decode($existingRaw, true);
                    if ($existing === $payload) {
                        $skipped++;
                        continue;
                    }
                    $this->components->error("{$filename} exists and differs; pass --force to overwrite.");

                    return self::FAILURE;
                }

                if ($this->option('dry-run')) {
                    $this->line('would write '.$filename);
                    $written++;
                    continue;
                }

                File::put($path, $encoded);
                $written++;
            }

            if ($this->option('write-aggregate') && ! $this->option('dry-run')) {
                // Newest first — same order as the legacy aggregate.
                $aggregate = array_reverse(array_map(static function (array $payload): array {
                    unset($payload['slug'], $payload['previous_version']);

                    return $payload;
                }, $filePayloads));

                File::put(
                    resource_path('data/releases.json'),
                    json_encode($aggregate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
                );
                $this->components->info('Rewrote resources/data/releases.json from per-file sources.');
            }

            $this->components->info(
                $this->option('dry-run')
                    ? "Dry-run: {$written} file(s) would be written ({$skipped} unchanged)."
                    : "Wrote {$written} release file(s) ({$skipped} already matched).",
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
