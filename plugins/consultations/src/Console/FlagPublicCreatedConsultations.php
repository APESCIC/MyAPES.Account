<?php

namespace Plugins\Consultations\Console;

use App\Core\Accounts\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\Consultations\Support\NonClinicalConsultationFlagger;

/**
 * Reports, and with confirmation flags for vet review, consultations created by public
 * users or staff without the Vet or Vet Nurse role (#539). Nothing is archived or deleted.
 */
class FlagPublicCreatedConsultations extends Command
{
    protected $signature = 'consultations:flag-public-created
        {--dry-run : Report the consultations without changing anything}
        {--force : Flag without asking for confirmation}';

    protected $description = 'Flag consultations created by non-clinical users for vet review';

    public function handle(NonClinicalConsultationFlagger $flagger): int
    {
        $findings = $flagger->findings();

        if ($findings->isEmpty()) {
            $this->components->info('No unflagged consultation was created by a non-clinical user.');

            return self::SUCCESS;
        }

        $this->table(
            ['Consultation', 'Created', 'Creator ID', 'Account', 'Source', 'Action'],
            $findings->map(static fn (array $finding): array => [
                $finding['consultation']->id,
                $finding['consultation']->created_at?->format('d/m/Y'),
                $finding['creator']?->id ?? '-',
                $finding['account'],
                $finding['source'],
                $finding['flag'] ? 'flag' : 'report only',
            ])->all(),
        );

        $report = $this->writeReport($findings);
        $this->components->info("Report written to storage/app/private/{$report}.");

        $toFlag = $findings->where('flag', true)->count();
        $unknown = $findings->count() - $toFlag;

        if ($unknown > 0) {
            $this->components->warn("{$unknown} consultation(s) have no recorded creator and are reported only.");
        }

        if ($this->option('dry-run')) {
            $this->components->warn("Dry run: {$toFlag} consultation(s) would be flagged for vet review. Nothing was changed.");

            return self::SUCCESS;
        }

        if ($toFlag === 0) {
            $this->components->info('Nothing to flag.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Flag {$toFlag} consultation(s) for vet review?")) {
            $this->components->warn('Cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        $flagged = $flagger->flag($findings);
        $this->components->info("Flagged {$flagged} consultation(s) for vet review. Nothing was archived or deleted.");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, array{consultation: PetCareConsultation, creator: ?User, account: string, source: string, flag: bool}>  $findings
     */
    private function writeReport(Collection $findings): string
    {
        $path = 'reports/consultations-non-clinical-'.now()->format('Ymd-His').'.csv';
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['consultation_id', 'created_at', 'creator_id', 'creator_account', 'source', 'action'], escape: '');

        foreach ($findings as $finding) {
            fputcsv($handle, [
                $finding['consultation']->id,
                $finding['consultation']->created_at?->toIso8601String(),
                $finding['creator']?->id,
                $finding['account'],
                $finding['source'],
                $finding['flag'] ? 'flag' : 'report_only',
            ], escape: '');
        }

        rewind($handle);
        Storage::disk('local')->put($path, (string) stream_get_contents($handle));
        fclose($handle);

        return $path;
    }
}
