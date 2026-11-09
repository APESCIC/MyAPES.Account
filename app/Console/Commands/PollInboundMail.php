<?php

namespace App\Console\Commands;

use App\Core\Mail\Inbound\InboundMailPoller;
use Illuminate\Console\Command;

/**
 * Fetches ticket and case replies from the communications mailbox (#579).
 * Scheduled every minute; does nothing until the mailbox is configured.
 */
class PollInboundMail extends Command
{
    protected $signature = 'tickets:inbound-poll';

    protected $description = 'Fetch ticket and case email replies from the communications mailbox';

    public function handle(InboundMailPoller $poller): int
    {
        $result = $poller->poll();

        if ($result['result'] === InboundMailPoller::RESULT_DISABLED) {
            $this->components->info('Replies by email are not configured; nothing to poll.');

            return self::SUCCESS;
        }

        if ($result['result'] === InboundMailPoller::RESULT_UNAVAILABLE) {
            $this->components->warn('The mailbox could not be reached. Mail stays there until the next poll.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Fetched %d, stored %d, already stored %d, failed %d.',
            $result['fetched'],
            $result['stored'],
            $result['duplicates'],
            $result['failed'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
