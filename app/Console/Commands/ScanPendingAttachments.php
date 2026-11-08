<?php

namespace App\Console\Commands;

use App\Core\Attachments\Scanning\VirusScanner;
use App\Core\Attachments\SupportAttachment;
use App\Jobs\ScanSupportAttachment;
use Illuminate\Console\Command;

/**
 * Queues scans for attachments still waiting on the scanner (#550).
 */
class ScanPendingAttachments extends Command
{
    protected $signature = 'attachments:scan-pending
        {--include-not-scanned : Also scan files uploaded while scanning was off}';

    protected $description = 'Queue virus scans for pending (and optionally not-scanned) support attachments';

    public function handle(VirusScanner $scanner): int
    {
        if (! $scanner->enabled()) {
            $this->components->error('No virus scanner is configured (ATTACHMENT_SCANNER is disabled).');

            return self::FAILURE;
        }

        $statuses = [SupportAttachment::SCAN_PENDING];
        if ($this->option('include-not-scanned')) {
            $statuses[] = SupportAttachment::SCAN_NOT_SCANNED;
        }

        $queued = 0;
        SupportAttachment::query()
            ->whereIn('scan_status', $statuses)
            ->orderBy('id')
            ->chunkById(200, function ($attachments) use (&$queued): void {
                foreach ($attachments as $attachment) {
                    $attachment->forceFill(['scan_status' => SupportAttachment::SCAN_PENDING])->save();
                    ScanSupportAttachment::dispatch((int) $attachment->id);
                    $queued++;
                }
            });

        $this->components->info("Queued {$queued} attachment scan(s).");

        return self::SUCCESS;
    }
}
