<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * One-off report of Admin/mail copy that still uses deprecated account terms (#874).
 * Does not rewrite stored overrides.
 */
class ReportDeprecatedMailCopyCommand extends Command
{
    protected $signature = 'myapes:report-deprecated-mail-copy';

    protected $description = 'List mail lang strings and Blade templates that still use deprecated Public/Staff user terms';

    public function handle(): int
    {
        $patterns = ['Public user', 'Public users', 'Staff user', 'Staff users', 'Service user', 'service-user'];
        $roots = [
            lang_path('en'),
            lang_path('en_GB'),
            resource_path('views/mail'),
        ];
        $hits = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            foreach (File::allFiles($root) as $file) {
                $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                if (! str_contains($relative, 'mail') && ! str_contains($relative, DIRECTORY_SEPARATOR.'mail'.DIRECTORY_SEPARATOR)) {
                    if (! str_ends_with($file->getFilename(), 'mail.php') && ! str_contains($relative, 'Notification')) {
                        continue;
                    }
                }
                $contents = $file->getContents();
                foreach ($patterns as $pattern) {
                    if (preg_match('/\\b'.preg_quote($pattern, '/').'\\b/i', $contents)) {
                        $hits[] = "{$relative}: {$pattern}";
                    }
                }
            }
        }

        if ($hits === []) {
            $this->info('No deprecated Public/Staff user terms found in mail lang or views.');

            return self::SUCCESS;
        }

        $this->warn('Deprecated terms still present (not changed automatically):');
        foreach ($hits as $hit) {
            $this->line(' - '.$hit);
        }

        return self::SUCCESS;
    }
}
