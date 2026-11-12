<?php

namespace App\Core\Mail\Inbound;

use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Moves new mail from the communications mailbox into the app (#579).
 *
 * A message is deleted from the mailbox only after it is stored (or was
 * stored before). If storing fails, or the mailbox is unreachable, it stays
 * where it is and the next poll tries again. Each poll fetches only UIDs
 * above the last one handled (#777); when the server's UIDVALIDITY changes
 * the folder is read again and the Message-ID check skips duplicates.
 */
final class InboundMailPoller
{
    public const RESULT_DISABLED = 'disabled';

    public const RESULT_UNAVAILABLE = 'unavailable';

    public const RESULT_POLLED = 'polled';

    public function __construct(
        private readonly InboundMailSettings $settings,
        private readonly InboundMailbox $mailbox,
        private readonly InboundMailStore $store,
        private readonly ReplyMailboxStatus $status,
    ) {}

    /**
     * @return array{result: string, fetched: int, stored: int, duplicates: int, failed: int}
     */
    public function poll(): array
    {
        $counts = ['result' => self::RESULT_POLLED, 'fetched' => 0, 'stored' => 0, 'duplicates' => 0, 'failed' => 0];

        if (! $this->settings->pollingConfigured()) {
            return ['result' => self::RESULT_DISABLED] + $counts;
        }

        $result = $this->fetch($counts);
        $this->status->recordPoll($result);

        return $result;
    }

    /**
     * @param  array{result: string, fetched: int, stored: int, duplicates: int, failed: int}  $counts
     * @return array{result: string, fetched: int, stored: int, duplicates: int, failed: int}
     */
    private function fetch(array $counts): array
    {
        try {
            $uidValidity = $this->mailbox->uidValidity();
            $state = InboundMailboxState::for($this->settings->imap('username') ?: $this->settings->address(), $this->settings->imap('folder') ?: 'INBOX');
            $after = $state->resumeAfter($uidValidity);
            $handled = $after ?? 0;
            $pending = [];

            foreach ($this->mailbox->messages(max(1, (int) config('inbound-mail.poll_batch', 50)), $after) as $message) {
                $counts['fetched']++;
                $uid = $message->uid();

                try {
                    $stored = $this->store->store($message->raw());
                } catch (Throwable $exception) {
                    $counts['failed']++;
                    if ($uid !== null) {
                        $pending[] = $uid;
                    }
                    Log::warning('Inbound email could not be stored; it stays in the mailbox.', [
                        'exception' => class_basename($exception),
                    ]);

                    continue;
                }

                $counts[$stored === null ? 'duplicates' : 'stored']++;
                $message->delete();
                $handled = max($handled, (int) $uid);
            }

            if ($counts['stored'] + $counts['duplicates'] > 0) {
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

            return ['result' => self::RESULT_UNAVAILABLE] + $counts;
        }

        return $counts;
    }
}
