<?php

namespace App\Console\Commands;

use App\Core\Attachments\AttachmentLimits;
use App\Core\Attachments\Scanning\VirusScanner;
use App\Core\Attachments\UploadLimitsProbe;
use Illuminate\Console\Command;

/**
 * Checks the upload set-up on this server (#697): virus scanner and PHP limits.
 */
class AttachmentDiagnostics extends Command
{
    /**
     * Room for form fields and multipart boundaries on top of the files themselves.
     */
    public const POST_OVERHEAD_KB = 4096;

    protected $signature = 'attachments:diagnose
        {--require-scanner : Fail when virus scanning is disabled}';

    protected $description = 'Check the virus scanner and the PHP upload limits for ticket and case attachments';

    public function handle(VirusScanner $scanner, UploadLimitsProbe $probe): int
    {
        $problems = 0;
        $driver = (string) config('myapes.attachments.scanner', 'disabled');
        $rows = [];

        if (! $scanner->enabled()) {
            $required = (bool) $this->option('require-scanner');
            $rows[] = ['Virus scanner', $driver, 'clamd', $required ? 'PROBLEM' : 'OFF (files are marked not scanned)'];
            $problems += $required ? 1 : 0;
        } else {
            $target = $driver === 'clamd'
                ? config('myapes.attachments.clamd.host').':'.config('myapes.attachments.clamd.port')
                : $driver;
            $reachable = $scanner->ping();
            $rows[] = ['Virus scanner', $target, 'answers', $reachable ? 'OK' : 'PROBLEM: no answer'];
            $problems += $reachable ? 0 : 1;
        }

        $needs = [
            'upload_max_filesize' => AttachmentLimits::SCREENCAST_MAX_KB * 1024,
            'post_max_size' => (AttachmentLimits::SUBMISSION_MAX_KB + self::POST_OVERHEAD_KB) * 1024,
            'max_file_uploads' => AttachmentLimits::SCREENSHOT_MAX_FILES
                + AttachmentLimits::SCREENCAST_MAX_FILES
                + AttachmentLimits::DOCUMENT_MAX_FILES,
        ];

        foreach ($probe->limits() as $setting => $values) {
            $need = $needs[$setting];
            $isCount = $setting === 'max_file_uploads';
            $effective = $isCount
                ? (int) ($values['effective'] ?? 0)
                : (int) UploadLimitsProbe::toBytes($values['effective']);
            $ok = $effective >= $need;
            $problems += $ok ? 0 : 1;

            $source = $values['htaccess'] !== null
                ? "{$values['htaccess']} (.htaccess; php.ini {$values['ini']})"
                : "{$values['ini']} (php.ini)";

            $rows[] = [
                $setting,
                $source,
                $isCount ? (string) $need : AttachmentLimits::megabytes(intdiv($need, 1024)),
                $ok ? 'OK' : 'PROBLEM: too low',
            ];
        }

        $this->table(['Check', 'Value', 'Needed', 'Result'], $rows);

        if ($problems > 0) {
            $this->components->error("{$problems} problem(s) found.");

            return self::FAILURE;
        }

        $this->components->info('Upload set-up looks fine.');

        return self::SUCCESS;
    }
}
