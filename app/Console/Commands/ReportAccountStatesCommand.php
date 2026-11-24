<?php

namespace App\Console\Commands;

use App\Core\Accounts\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Counts users in each account state after the v0.76.0 backfill (#911).
 */
class ReportAccountStatesCommand extends Command
{
    protected $signature = 'myapes:accounts:report-states';

    protected $description = 'Report how many accounts are profile only, invited or active';

    public function handle(): int
    {
        if (! Schema::hasColumn('users', 'account_state')) {
            $this->components->error('The account_state column is missing. Run migrations first.');

            return self::FAILURE;
        }

        $counts = User::query()
            ->selectRaw('account_state, COUNT(*) as aggregate')
            ->groupBy('account_state')
            ->pluck('aggregate', 'account_state');

        $rows = [
            [User::ACCOUNT_STATE_PROFILE_ONLY, (int) ($counts[User::ACCOUNT_STATE_PROFILE_ONLY] ?? 0)],
            [User::ACCOUNT_STATE_INVITED, (int) ($counts[User::ACCOUNT_STATE_INVITED] ?? 0)],
            [User::ACCOUNT_STATE_ACTIVE, (int) ($counts[User::ACCOUNT_STATE_ACTIVE] ?? 0)],
        ];

        $this->table(['State', 'Count'], $rows);
        $this->components->info('Total: '.$counts->sum());

        return self::SUCCESS;
    }
}
