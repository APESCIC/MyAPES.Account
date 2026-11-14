<?php

namespace App\Core\Mail\Inbound;

use App\Jobs\ProcessInboundEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Held, unmatched and failed mail waiting on the Import monitor (#814).
 */
final class ImportQueue
{
    public const QUEUE_STATUSES = [
        InboundEmail::STATUS_QUARANTINED,
        InboundEmail::STATUS_FAILED,
    ];

    /**
     * How many held or failed items are waiting (menu badge).
     */
    public function badgeCount(): int
    {
        return (int) InboundEmail::query()
            ->whereIn('status', self::QUEUE_STATUSES)
            ->count();
    }

    /**
     * @return list<array{
     *     id: int,
     *     status: string,
     *     status_label: string,
     *     reason: ?string,
     *     age: string,
     *     sender: string,
     *     subject: ?string,
     *     can_retry: bool,
     *     can_attach: bool,
     *     can_discard: bool,
     *     show_url: string,
     * }>
     */
    public function items(): array
    {
        /** @var Collection<int, InboundEmail> $emails */
        $emails = InboundEmail::query()
            ->with('user:id,name,email')
            ->whereIn('status', self::QUEUE_STATUSES)
            ->latest('id')
            ->limit(100)
            ->get([
                'id',
                'status',
                'reason',
                'from_address',
                'from_domain',
                'from_name',
                'subject',
                'raw_path',
                'raw_pruned_at',
                'user_id',
                'received_at',
            ]);

        return $emails->map(function (InboundEmail $email): array {
            return [
                'id' => (int) $email->id,
                'status' => (string) $email->status,
                'status_label' => $email->statusLabel(),
                'reason' => $email->reasonLabel(),
                'age' => $this->ageLabel($email->received_at),
                'sender' => $email->user?->name
                    ?? $email->from_address
                    ?? $email->from_domain
                    ?? __('admin.emails.import.monitor.unknown_sender'),
                'subject' => $email->hasRaw() ? $email->subject : null,
                'can_retry' => $email->isActionable(),
                'can_attach' => $email->status === InboundEmail::STATUS_QUARANTINED && $email->hasRaw(),
                'can_discard' => $email->isActionable(),
                'show_url' => route('admin.emails.inbound.show', $email),
            ];
        })->all();
    }

    /**
     * Retries every failed item that still has its raw message. Returns how many were queued.
     */
    public function retryAllFailed(): int
    {
        $ids = InboundEmail::query()
            ->where('status', InboundEmail::STATUS_FAILED)
            ->whereNotNull('raw_path')
            ->whereNull('raw_pruned_at')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        InboundEmail::query()
            ->whereIn('id', $ids)
            ->update([
                'status' => InboundEmail::STATUS_RECEIVED,
                'reason' => null,
                'detail' => null,
                'processed_at' => null,
                'updated_at' => now(),
            ]);

        foreach ($ids as $id) {
            ProcessInboundEmail::dispatch((int) $id);
        }

        return $ids->count();
    }

    private function ageLabel(?Carbon $receivedAt): string
    {
        if ($receivedAt === null) {
            return __('admin.emails.import.queue.age_unknown');
        }

        return $receivedAt->diffForHumans();
    }
}
