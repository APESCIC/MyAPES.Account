<?php

namespace App\Jobs;

use App\Core\Accounts\User;
use App\Core\Mail\StaffMessage;
use App\Notifications\StaffMessageNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Sends a staff message in chunks with rate limits (#505).
 */
class SendStaffMessageJob implements ShouldQueue
{
    use Queueable;

    private const CHUNK_SIZE = 50;

    public int $tries = 100;

    public int $maxExceptions = 5;

    public function __construct(
        public int $staffMessageId,
    ) {
        $this->afterCommit();
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDay();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited('staff-messages')];
    }

    public function handle(): void
    {
        $message = StaffMessage::query()->with('sender')->find($this->staffMessageId);

        if ($message === null || $message->sender === null) {
            return;
        }

        if (! in_array($message->status, [StaffMessage::STATUS_QUEUED, StaffMessage::STATUS_SENDING], true)) {
            return;
        }

        if ($message->status === StaffMessage::STATUS_QUEUED) {
            $message->update(['status' => StaffMessage::STATUS_SENDING]);
        }

        $recipientIds = array_values(array_map(
            static fn ($id): int => (int) $id,
            is_array($message->recipient_ids) ? $message->recipient_ids : [],
        ));

        if ($recipientIds === []) {
            $this->finish($message);

            return;
        }

        $offset = max(0, (int) $message->send_offset);
        $chunkIds = array_slice($recipientIds, $offset, self::CHUNK_SIZE);

        if ($chunkIds === []) {
            $this->finish($message);

            return;
        }

        $users = User::query()
            ->whereIn('id', $chunkIds)
            ->get()
            ->sortBy(static fn (User $user): int => array_search($user->id, $chunkIds, true))
            ->values();

        $sender = $message->sender;
        $processed = 0;

        foreach ($users as $recipient) {
            if (! $this->consumeSenderQuota($sender->id)) {
                $message->update(['send_offset' => $offset + $processed]);
                $this->release(3600);

                return;
            }

            try {
                Notification::sendNow($recipient, new StaffMessageNotification($message, $sender));
                $message->increment('sent_count');
            } catch (Throwable $exception) {
                $message->increment('failed_count');
                Log::warning('Staff message recipient delivery failed.', [
                    'staff_message_id' => $message->id,
                    'recipient_id' => $recipient->id,
                    'exception' => class_basename($exception),
                ]);
            }

            $processed++;
            $message->update(['send_offset' => $offset + $processed]);
        }

        $nextOffset = $offset + $processed;

        if ($nextOffset < count($recipientIds)) {
            self::dispatch($message->id);

            return;
        }

        $this->finish($message);
    }

    public function failed(?Throwable $exception): void
    {
        StaffMessage::query()
            ->whereKey($this->staffMessageId)
            ->whereIn('status', [StaffMessage::STATUS_QUEUED, StaffMessage::STATUS_SENDING])
            ->update([
                'status' => StaffMessage::STATUS_FAILED,
                'sent_at' => now(),
            ]);
    }

    private function finish(StaffMessage $message): void
    {
        $message->refresh();

        $status = $message->failed_count > 0 && $message->sent_count === 0
            ? StaffMessage::STATUS_FAILED
            : StaffMessage::STATUS_SENT;

        $message->update([
            'status' => $status,
            'sent_at' => now(),
        ]);
    }

    private function consumeSenderQuota(int $senderId): bool
    {
        $key = 'staff-messages:sender:'.$senderId;

        if (RateLimiter::tooManyAttempts($key, 500)) {
            return false;
        }

        RateLimiter::hit($key, 86400);

        return true;
    }
}
