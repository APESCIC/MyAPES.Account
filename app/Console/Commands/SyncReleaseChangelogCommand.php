<?php

namespace App\Console\Commands;

use App\Support\Releases\ReleaseChangelogSync;
use Illuminate\Console\Command;
use Throwable;

class SyncReleaseChangelogCommand extends Command
{
    protected $signature = 'myapes:releases-sync
        {--dry-run : Validate every release file without writing to the database}
        {--check : Alias of --dry-run}';

    protected $description = 'Sync changelog/releases/*.json into the releases projection table (#704)';

    public function handle(ReleaseChangelogSync $sync): int
    {
        try {
            if ($this->option('dry-run') || $this->option('check')) {
                $payloads = $sync->validateAll();
                foreach ($payloads as $entry) {
                    $this->line('OK '.$entry['path']);
                }
                $this->components->info(count($payloads).' release file(s) validated (dry-run).');

                return self::SUCCESS;
            }

            $count = $sync->sync();
            $this->components->info("Synced {$count} release(s) into the database.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // Message only — never dump release body text into logs.
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
