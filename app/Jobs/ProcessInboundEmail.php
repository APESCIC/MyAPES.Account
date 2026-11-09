<?php

namespace App\Jobs;

use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Matches, checks and posts one stored inbound email (#579).
 */
class ProcessInboundEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public int $inboundEmailId,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(InboundMailProcessor $processor): void
    {
        $email = InboundEmail::query()->find($this->inboundEmailId);

        if ($email === null || $email->status !== InboundEmail::STATUS_RECEIVED) {
            return;
        }

        $processor->process($email);
    }

    public function failed(?Throwable $exception): void
    {
        InboundEmail::query()
            ->whereKey($this->inboundEmailId)
            ->where('status', InboundEmail::STATUS_RECEIVED)
            ->update(['status' => InboundEmail::STATUS_FAILED, 'reason' => 'processing_error', 'processed_at' => now()]);

        Log::warning('Inbound email could not be processed.', [
            'inbound_email_id' => $this->inboundEmailId,
            'exception' => $exception === null ? null : class_basename($exception),
        ]);
    }
}
