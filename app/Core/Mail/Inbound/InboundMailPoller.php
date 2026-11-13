<?php

namespace App\Core\Mail\Inbound;

use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\Contracts\MailboxMessage;
use App\Core\Mail\Inbound\Diagnostics\FailureType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Moves new mail from the communications mailbox into the app (#579).
 *
 * A message leaves the folder only after it is stored (or was stored
 * before): it moves to Processed, so it stays visible in the mailbox (#776).
 * If storing fails it stays where it is and the next poll tries again; after
 * the third failure it moves to Failed. Each poll fetches only UIDs above
 * the last one handled (#777); when the server's UIDVALIDITY changes the
 * folder is read again and the Message-ID check skips duplicates.
 */
final class InboundMailPoller
{
    public const RESULT_DISABLED = 'disabled';

    public const RESULT_UNAVAILABLE = 'unavailable';

    public const RESULT_POLLED = 'polled';

    public const RESULT_BACKING_OFF = 'backing_off';

    public const RESULT_LOCKED = 'locked';

    private const LOCK = 'inbound-mail:poll-lock';

    private const LOCK_SECONDS = 300;

    public const FOLDER_PROCESSED = 'Processed';

    public const FOLDER_FAILED = 'Failed';

    public const MAX_STORE_ATTEMPTS = 3;

    public function __construct(
        private readonly InboundMailSettings $settings,
        private readonly InboundMailbox $mailbox,
        private readonly InboundMailStore $store,
        private readonly ReplyMailboxStatus $status,
        private readonly ImportAlerts $alerts,
    ) {}

    /**
     * Polls once. Scheduled polls wait while backing off after failures; an
     * admin's "Fetch replies now" ($force) does not. Two polls never overlap (#778).
     *
     * @return array{result: string, failure?: string, fetched: int, stored: int, duplicates: int, failed: int}
     */
    public function poll(bool $force = false): array
    {
        $counts = ['result' => self::RESULT_POLLED, 'fetched' => 0, 'stored' => 0, 'duplicates' => 0, 'failed' => 0];

        if (! $this->settings->pollingConfigured()) {
            return ['result' => self::RESULT_DISABLED] + $counts;
        }

        if (! $force && $this->status->backingOff()) {
            return ['result' => self::RESULT_BACKING_OFF] + $counts;
        }

        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return ['result' => self::RESULT_LOCKED] + $counts;
        }

        $started = hrtime(true);
        try {
            $result = $this->fetch($counts);
        } finally {
            $lock->release();
        }

        $this->status->recordPoll($result);
        InboundMailboxLogEntry::recordPoll($result, (int) round((hrtime(true) - $started) / 1_000_000));
        $this->alerts->afterPoll($result);

        return $result;
    }

    /**
     * @param  array{result: string, failure?: string, fetched: int, stored: int, duplicates: int, failed: int}  $counts
     * @return array{result: string, failure?: string, fetched: int, stored: int, duplicates: int, failed: int}
     */
    private function fetch(array $counts): array
    {
        try {
            $this->mailbox->ensureFolders([self::FOLDER_PROCESSED, self::FOLDER_FAILED]);
            $uidValidity = $this->mailbox->uidValidity();
            $state = InboundMailboxState::for($this->mailboxKey(), $this->settings->imap('folder') ?: 'INBOX');
            $after = $state->resumeAfter($uidValidity);
            $handled = $after ?? 0;
            $pending = [];
            $moved = 0;

            foreach ($this->mailbox->messages(max(1, (int) config('inbound-mail.poll_batch', 50)), $after) as $message) {
                $counts['fetched']++;
                $uid = $message->uid();

                try {
                    $stored = $this->store->store($message->raw());
                } catch (Throwable $exception) {
                    $counts['failed']++;
                    Log::warning('Inbound email could not be stored; it stays in the mailbox.', [
                        'exception' => class_basename($exception),
                    ]);

                    if ($this->giveUp($message, $uidValidity)) {
                        $message->moveTo(self::FOLDER_FAILED);
                        $moved++;
                        $handled = max($handled, (int) $uid);
                    } elseif ($uid !== null) {
                        $pending[] = $uid;
                    }

                    continue;
                }

                $counts[$stored === null ? 'duplicates' : 'stored']++;
                $message->moveTo(self::FOLDER_PROCESSED);
                $moved++;
                $this->forgetAttempts($message, $uidValidity);
                $handled = max($handled, (int) $uid);
            }

            if ($moved > 0) {
                $this->mailbox->expunge();
            }

            if ($uidValidity !== null) {
                // Mail that failed to store stays in the folder, so the next poll starts just below it.
                $state->fill(['uid_validity' => $uidValidity, 'last_uid' => $pending !== [] ? min($pending) - 1 : $handled])->save();
            }
        } catch (MailboxUnavailable $exception) {
            Log::warning('Inbound mailbox unavailable; mail stays in the mailbox until the next poll.', [
                'reason' => $exception->getMessage(),
            ]);

            return ['result' => self::RESULT_UNAVAILABLE, 'failure' => ($exception->type ?? FailureType::ConnectionRefused)->value] + $counts;
        }

        return $counts;
    }

    /**
     * Counts a failed store; true once the message has failed MAX_STORE_ATTEMPTS times.
     */
    private function giveUp(MailboxMessage $message, ?int $uidValidity): bool
    {
        $key = $this->attemptsKey($message, $uidValidity);
        if ($key === null) {
            return false;
        }

        $attempts = (int) Cache::get($key, 0) + 1;
        if ($attempts >= self::MAX_STORE_ATTEMPTS) {
            Cache::forget($key);

            return true;
        }

        Cache::put($key, $attempts, now()->addDays($this->settings->retentionDays()));

        return false;
    }

    private function forgetAttempts(MailboxMessage $message, ?int $uidValidity): void
    {
        $key = $this->attemptsKey($message, $uidValidity);
        if ($key !== null) {
            Cache::forget($key);
        }
    }

    private function attemptsKey(MailboxMessage $message, ?int $uidValidity): ?string
    {
        $uid = $message->uid();

        return $uid === null ? null : 'inbound-mail:store-attempts:'.sha1($this->mailboxKey()).':'.($uidValidity ?? 0).':'.$uid;
    }

    private function mailboxKey(): string
    {
        return strtolower($this->settings->imap('username') ?: $this->settings->address());
    }
}
