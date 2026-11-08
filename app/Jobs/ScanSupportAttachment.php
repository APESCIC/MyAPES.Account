<?php

namespace App\Jobs;

use App\Core\Attachments\Scanning\ScannerUnavailable;
use App\Core\Attachments\Scanning\ScanResult;
use App\Core\Attachments\Scanning\VirusScanner;
use App\Core\Attachments\SupportAttachment;
use App\Notifications\AttachmentQuarantinedNotification;
use App\Services\AuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Scans one attachment (#550). Pending files cannot be downloaded; clean files
 * are released, infected files are moved to quarantine and the uploader is told.
 */
class ScanSupportAttachment implements ShouldQueue
{
    use Queueable;

    public const QUARANTINE_DIRECTORY = 'quarantine';

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(
        public int $attachmentId,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(VirusScanner $scanner, AuditLogger $audit): void
    {
        $attachment = SupportAttachment::query()->find($this->attachmentId);

        if ($attachment === null || $attachment->scan_status !== SupportAttachment::SCAN_PENDING) {
            return;
        }

        if (! $scanner->enabled()) {
            $attachment->forceFill(['scan_status' => SupportAttachment::SCAN_NOT_SCANNED])->save();

            return;
        }

        try {
            $result = $this->scan($scanner, $attachment);
        } catch (ScannerUnavailable $exception) {
            Log::warning('Attachment scan postponed: scanner unavailable.', [
                'attachment_id' => $attachment->id,
                'attempt' => $this->attempts(),
            ]);
            $backoff = $this->backoff();
            $this->release($backoff[min(max($this->attempts() - 1, 0), count($backoff) - 1)]);

            return;
        }

        if (! $result->infected) {
            $attachment->forceFill([
                'scan_status' => SupportAttachment::SCAN_CLEAN,
                'scanned_at' => now(),
            ])->save();

            return;
        }

        $this->quarantine($attachment);
        $audit->record('support_attachment.infected', null, $attachment, [
            'kind' => $attachment->kind,
            'signature' => $result->signature,
            'attachable_type' => $attachment->attachable_type,
            'attachable_id' => $attachment->attachable_id,
        ]);

        $recordUrl = $attachment->recordUrl();
        if ($attachment->user !== null && $recordUrl !== null) {
            $attachment->user->notify(new AttachmentQuarantinedNotification(
                $attachment->original_name,
                $recordUrl,
            ));
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Attachment scan did not finish; the file stays pending.', [
            'attachment_id' => $this->attachmentId,
            'reason' => $exception?->getMessage(),
        ]);
    }

    private function scan(VirusScanner $scanner, SupportAttachment $attachment): ScanResult
    {
        $stream = Storage::disk($attachment->disk)->readStream($attachment->path);
        if (! is_resource($stream)) {
            throw new ScannerUnavailable("Attachment {$attachment->id} could not be read for scanning.");
        }

        try {
            return $scanner->scan($stream);
        } finally {
            fclose($stream);
        }
    }

    private function quarantine(SupportAttachment $attachment): void
    {
        $disk = Storage::disk($attachment->disk);
        $target = self::QUARANTINE_DIRECTORY.'/'.$attachment->path;

        if ($disk->exists($attachment->path)) {
            $disk->move($attachment->path, $target);
        }

        $attachment->forceFill([
            'path' => $target,
            'scan_status' => SupportAttachment::SCAN_INFECTED,
            'scanned_at' => now(),
        ])->save();
    }
}
