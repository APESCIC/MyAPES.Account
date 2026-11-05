<?php

namespace App\Console\Commands;

use App\Core\Accounts\ClientRecords\StaffClientRecordArchiver;
use App\Core\Accounts\User;
use Illuminate\Console\Command;

/**
 * Restores client records archived by myapes:accounts:detach-staff-client-records (#514).
 */
class RestoreStaffClientRecords extends Command
{
    protected $signature = 'myapes:accounts:restore-staff-client-records
        {user : User ID or email address}
        {--force : Restore without asking for confirmation}';

    protected $description = 'Restore archived client records for one account';

    public function handle(StaffClientRecordArchiver $archiver): int
    {
        $identifier = trim((string) $this->argument('user'));
        $user = ctype_digit($identifier)
            ? User::query()->find((int) $identifier)
            : User::query()->whereRaw('LOWER(email) = ?', [strtolower($identifier)])->first();

        if ($user === null) {
            $this->components->error('No account matches that user ID or email.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Restore archived client records for user {$user->id}?")) {
            $this->components->warn('Cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        $restored = $archiver->restore($user);

        if ($restored === []) {
            $this->components->info("User {$user->id} has no archived client records.");

            return self::SUCCESS;
        }

        foreach ($restored as $type => $count) {
            $this->components->twoColumnDetail($type, (string) $count);
        }
        $this->components->info('Restored '.array_sum($restored)." record(s) for user {$user->id}.");

        return self::SUCCESS;
    }
}
