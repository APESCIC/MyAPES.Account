<?php

namespace App\Console\Commands;

use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailProcessor;
use App\Core\Mail\Inbound\InboundMailStore;
use Illuminate\Console\Command;

/**
 * Feeds a saved .eml file through replies by email (#587), exactly as if it
 * had arrived in the mailbox, and processes it straight away.
 */
class IngestInboundMail extends Command
{
    protected $signature = 'tickets:inbound-ingest {file : Path to a .eml file}';

    protected $description = 'Store and process one email reply from a .eml file (local testing and support)';

    public function handle(InboundMailStore $store, InboundMailProcessor $processor): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file) || ! is_readable($file)) {
            $this->components->error('That file does not exist or cannot be read.');

            return self::FAILURE;
        }

        $email = $store->store((string) file_get_contents($file), 'file');

        if ($email === null) {
            $this->components->warn('This email (same Message-ID) was already received; nothing changed.');

            return self::SUCCESS;
        }

        $email->refresh();

        if ($email->status === InboundEmail::STATUS_RECEIVED) {
            $processor->process($email);
            $email->refresh();
        }

        $this->components->twoColumnDetail('Inbound email', '#'.$email->id);
        $this->components->twoColumnDetail('Status', __('admin.emails.inbound.statuses.'.$email->status));
        $this->components->twoColumnDetail('Reason', $email->reasonLabel() ?? '—');
        $this->components->twoColumnDetail('Record', $email->record_prefix === null ? '—' : $email->record_prefix.'-'.$email->record_id);

        return self::SUCCESS;
    }
}
