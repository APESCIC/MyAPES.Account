<?php

namespace App\Console\Commands;

use App\Core\Mail\Inbound\RetireStoredMailboxSettings;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Deletes old inbound_mailbox app_settings rows by hand (#809).
 */
class RetireStoredMailboxSettingsCommand extends Command
{
    protected $signature = 'inbound-mail:retire-stored-settings';

    protected $description = 'Delete the old Admin Import mailbox settings once a Keys and secrets or .env password is in use';

    public function handle(RetireStoredMailboxSettings $retire): int
    {
        if (! $retire->hasStoredRows()) {
            $this->info('No stored mailbox settings to retire.');

            return self::SUCCESS;
        }

        try {
            $keys = $retire->retire();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Retired stored mailbox settings: '.implode(', ', $keys));

        return self::SUCCESS;
    }
}
