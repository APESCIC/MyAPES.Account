<?php

namespace App\Console\Commands;

use App\Core\Attachments\SupportAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Moves support attachments from the public disk onto the non-serving private
 * disk and updates the disk column (#554).
 *
 * Dry run by default listing. --force applies the move. --reverse moves
 * private-disk rows back to public for rollback.
 */
class MoveAttachmentsToPrivate extends Command
{
    protected $signature = 'attachments:move-to-private
        {--dry-run : List attachments that would move without changing anything}
        {--force : Apply the move (required unless --dry-run)}
        {--reverse : Move private-disk attachments back to the public disk}';

    protected $description = 'Move ticket and case support attachments between public and private disks';

    public function handle(): int
    {
        $reverse = (bool) $this->option('reverse');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if (! $dryRun && ! $force) {
            $this->components->error('Refuse to run without --dry-run or --force.');

            return self::FAILURE;
        }

        $fromDisk = $reverse ? 'private' : 'public';
        $toDisk = $reverse ? 'public' : 'private';
        $direction = $reverse ? 'private → public' : 'public → private';

        $attachments = SupportAttachment::query()
            ->withTrashed()
            ->where('disk', $fromDisk)
            ->orderBy('id')
            ->get();

        if ($attachments->isEmpty()) {
            $this->components->info("No support attachments on the {$fromDisk} disk.");

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Kind', 'Path', 'Exists on source'],
            $attachments->map(static fn (SupportAttachment $attachment): array => [
                $attachment->id,
                $attachment->kind,
                $attachment->path,
                Storage::disk($fromDisk)->exists($attachment->path) ? 'yes' : 'missing',
            ])->all(),
        );

        $this->components->info("{$attachments->count()} attachment(s) for {$direction}.");

        if ($dryRun) {
            $this->components->warn('Dry run: nothing was moved. Re-run with --force to apply.');
            Log::info('attachments:move-to-private dry-run', [
                'direction' => $direction,
                'count' => $attachments->count(),
                'ids' => $attachments->pluck('id')->all(),
            ]);

            return self::SUCCESS;
        }

        $moved = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($attachments as $attachment) {
            try {
                if (! Storage::disk($fromDisk)->exists($attachment->path)) {
                    $this->components->warn("Skip #{$attachment->id}: missing on {$fromDisk} ({$attachment->path}).");
                    $skipped++;
                    Log::warning('attachments:move-to-private missing source', [
                        'id' => $attachment->id,
                        'from' => $fromDisk,
                        'path' => $attachment->path,
                    ]);

                    continue;
                }

                $contents = Storage::disk($fromDisk)->get($attachment->path);
                Storage::disk($toDisk)->put($attachment->path, $contents);

                if (! Storage::disk($toDisk)->exists($attachment->path)) {
                    throw new \RuntimeException("Copy to {$toDisk} did not persist for path {$attachment->path}.");
                }

                $attachment->disk = $toDisk;
                $attachment->save();

                Storage::disk($fromDisk)->delete($attachment->path);
                $moved++;

                Log::info('attachments:move-to-private moved', [
                    'id' => $attachment->id,
                    'from' => $fromDisk,
                    'to' => $toDisk,
                    'path' => $attachment->path,
                ]);
            } catch (Throwable $exception) {
                $failed++;
                $this->components->error("Failed #{$attachment->id}: {$exception->getMessage()}");
                Log::error('attachments:move-to-private failed', [
                    'id' => $attachment->id,
                    'from' => $fromDisk,
                    'to' => $toDisk,
                    'path' => $attachment->path,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->components->info("Moved {$moved}; skipped {$skipped}; failed {$failed} ({$direction}).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
