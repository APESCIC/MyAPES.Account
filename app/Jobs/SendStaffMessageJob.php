<?php

namespace App\Jobs;

use App\Core\Mail\StaffMessage;
use App\Core\Mail\StaffMessageRecipientResolver;
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

    public int $tries = 25;

    public function __construct(
        public int $staffMessageId,
        public int $offset = 0,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited('staff-messages')];
    }

    public function handle(StaffMessageRecipientResolver $resolver): void
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

        $sender = $message->sender;
        $resolution = $resolver->resolve(
            $sender,
            $message->recipient_filter,
            $message->category,
            $sender->can('staff.messages.send.all'),
        );

        $recipientIds = $resolution->recipients->pluck('id')->values()->all();
        $chunkIds = array_slice($recipientIds, $this->offset, self::CHUNK_SIZE);

        if ($chunkIds === []) {
            $this->finish($message);

            return;
        }

        $users = $resolution->recipients
            ->whereIn('id', $chunkIds)
            ->values();

        foreach ($users as $recipient) {
            if (! $this->consumeSenderQuota($sender->id)) {
                $this->release(3600);

                return;
            }

            try {
                Notification::send($recipient, new StaffMessageNotification($message, $sender));
                $message->increment('sent_count');
            } catch (Throwable $exception) {
                $message->increment('failed_count');
                Log::warning('Staff message recipient delivery failed.', [
                    'staff_message_id' => $message->id,
                    'recipient_id' => $recipient->id,
                    'exception' => class_basename($exception),
                ]);
            }
        }

        $nextOffset = $this->offset + self::CHUNK_SIZE;

        if ($nextOffset < count($recipientIds)) {
            self::dispatch($message->id, $nextOffset);

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