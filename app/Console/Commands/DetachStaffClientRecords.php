<?php

namespace App\Console\Commands;

use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\ClientRecords\StaffClientRecordArchiver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Reports, and with confirmation archives, client records owned by staff-side
 * accounts (#514).
 *
 * Records are archived, never deleted, and myapes:accounts:restore-staff-client-records
 * brings them back. Each affected account is emailed a link to register a
 * separate public account.
 */
class DetachStaffClientRecords extends Command
{
    protected $signature = 'myapes:accounts:detach-staff-client-records
        {--dry-run : Report the records without changing anything}
        {--force : Archive without asking for confirmation}';

    protected $description = 'Archive client records held by staff, volunteer, student and admin accounts';

    public function handle(StaffClientRecordArchiver $archiver, AccountClassifier $classifier): int
    {
        $findings = $archiver->findings();

        if ($findings->isEmpty()) {
            $this->components->info('No staff-side account holds active client records.');

            return self::SUCCESS;
        }

        $this->table(
            ['User ID', 'Email', 'Account', 'Records', 'Count'],
            $findings->map(static fn (array $finding): array => [
                $finding['user']->id,
                $finding['user']->email,
                $classifier->classify($finding['user']),
                $finding['type']->label,
                count($finding['ids']),
            ])->all(),
        );

        $total = $findings->sum(static fn (array $finding): int => count($finding['ids']));
        $accounts = $findings->pluck('user.id')->unique()->count();
        $report = $this->writeReport($findings, $classifier);
        $this->components->info("Report written to storage/app/private/{$report}.");

        if ($this->option('dry-run')) {
            $this->components->warn("Dry run: {$total} record(s) on {$accounts} account(s) would be archived. Nothing was changed.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Archive {$total} record(s) on {$accounts} account(s) and email those accounts?")) {
            $this->components->warn('Cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        $archived = $archiver->archive($findings);
        $this->components->info("Archived {$archived} record(s) on {$accounts} account(s). Nothing was deleted.");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, array{user: \App\Core\Accounts\User, type: \App\Core\Accounts\ClientRecords\ClientRecordType, ids: list<int>}>  $findings
     */
    private function writeReport(Collection $findings, AccountClassifier $classifier): string
    {
        $path = 'reports/staff-client-records-'.now()->format('Ymd-His').'.csv';
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['user_id', 'email', 'account', 'record_type', 'record_id'], escape: '');

        foreach ($findings as $finding) {
            foreach ($finding['ids'] as $id) {
                fputcsv($handle, [
                    $finding['user']->id,
                    $finding['user']->email,
                    $classifier->classify($finding['user']),
                    $finding['type']->key,
                    $id,
                ], escape: '');
            }
        }

        rewind($handle);
        Storage::disk('local')->put($path, (string) stream_get_contents($handle));
        fclose($handle);

        return $path;
    }
}
