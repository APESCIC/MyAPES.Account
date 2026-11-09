<?php

namespace App\Core\Mail\Inbound;

use Illuminate\Support\Facades\Storage;

/**
 * Retention for replies by email (#586). After the retention period the raw
 * message is deleted and the row keeps only metadata: when it arrived, what
 * happened and why, the record, the sender's account id and a hashed address.
 * Message-ID threads used for matching are kept a little longer.
 */
final class InboundMailPruner
{
    public const THREAD_RETENTION_DAYS = 180;

    public function __construct(private readonly InboundMailSettings $settings) {}

    /**
     * @return array{emails: int, threads: int}
     */
    public function prune(?int $days = null): array
    {
        $cutoff = now()->subDays($days ?? $this->settings->retentionDays());
        $emails = 0;

        InboundEmail::query()
            ->whereNull('raw_pruned_at')
            ->where('created_at', '<', $cutoff)
            ->where('status', '!=', InboundEmail::STATUS_RECEIVED)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$emails): void {
                foreach ($rows as $email) {
                    if (is_string($email->raw_path) && $email->raw_path !== '') {
                        Storage::disk(InboundEmail::RAW_DISK)->delete($email->raw_path);
                    }

                    $email->forceFill([
                        'raw_path' => null,
                        'from_address' => null,
                        'from_name' => null,
                        'subject' => null,
                        'detail' => null,
                        'message_id' => null,
                        'raw_pruned_at' => now(),
                    ])->save();
                    $emails++;
                }
            });

        $threads = MailReplyThread::query()
            ->where('created_at', '<', now()->subDays(max(self::THREAD_RETENTION_DAYS, $days ?? 0)))
            ->delete();

        return ['emails' => $emails, 'threads' => $threads];
    }
}
