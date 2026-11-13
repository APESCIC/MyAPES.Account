<?php

namespace App\Console\Commands;

use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\MailboxUnavailable;
use Illuminate\Console\Command;

/**
 * Empties messages older than 30 days from the mailbox's Processed and
 * Failed folders (#776). Runs daily; does nothing until polling is set up.
 */
class EmptyInboundMailFolders extends Command
{
    protected $signature = 'inbound-mail:empty-folders {--days= : Days of mail to keep in Processed and Failed}';

    protected $description = 'Delete mail older than the retention period from the mailbox Processed and Failed folders';

    public function handle(InboundMailSettings $settings, InboundMailbox $mailbox): int
    {
        if (! $settings->pollingConfigured()) {
            $this->components->info('Replies by email are not configured; nothing to empty.');

            return self::SUCCESS;
        }

        $days = $this->option('days');
        $days = is_numeric($days) ? max(1, (int) $days) : max(1, (int) config('inbound-mail.folder_retention_days', 30));

        try {
            foreach ([InboundMailPoller::FOLDER_PROCESSED, InboundMailPoller::FOLDER_FAILED] as $folder) {
                $this->components->info(sprintf('Deleted %d message(s) older than %d days from %s.', $mailbox->purge($folder, $days), $days, $folder));
            }
        } catch (MailboxUnavailable) {
            $this->components->warn('The mailbox could not be reached. The folders are emptied on the next run.');
        }

        return self::SUCCESS;
    }
}
