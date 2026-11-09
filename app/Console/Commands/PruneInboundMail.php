<?php

namespace App\Console\Commands;

use App\Core\Mail\Inbound\InboundMailPruner;
use Illuminate\Console\Command;

/**
 * Deletes raw inbound replies after the retention period, keeping metadata (#586).
 */
class PruneInboundMail extends Command
{
    protected $signature = 'inbound-mail:prune {--days= : Days of raw inbound email to keep}';

    protected $description = 'Delete raw ticket and case email replies after the retention period, keeping metadata only';

    public function handle(InboundMailPruner $pruner): int
    {
        $days = $this->option('days');
        $result = $pruner->prune(is_numeric($days) ? max(1, (int) $days) : null);

        $this->components->info("Pruned {$result['emails']} inbound email(s) and {$result['threads']} old reply thread(s).");

        return self::SUCCESS;
    }
}
