<?php

namespace App\Console\Commands;

use App\Core\Mail\Inbound\InboundMailSettings;
use Illuminate\Console\Command;

/**
 * Deploy preflight for replies by email (#756): a release must poll the real
 * mailbox over IMAP, never Laragon's local Mailpit.
 */
class InboundMailPreflight extends Command
{
    protected $signature = 'myapes:inbound-mail:preflight';

    protected $description = 'Fail the deploy if replies by email are set to a local-only mailbox driver';

    public function handle(InboundMailSettings $settings): int
    {
        if ($settings->driver() !== InboundMailSettings::DRIVER_IMAP) {
            $this->components->error(sprintf(
                'Inbound mail preflight: failed (INBOUND_MAIL_DRIVER is "%s"; remove it so the release polls over IMAP)',
                $settings->driver(),
            ));

            return self::FAILURE;
        }

        $this->components->info('Inbound mail preflight: ok (imap)');

        return self::SUCCESS;
    }
}
