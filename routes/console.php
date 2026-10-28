<?php

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\DirectorySyncRun;
use App\Core\Mail\OutboundMailLog;
use App\Jobs\RunDirectorySync;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('audit:prune {--days= : Number of days of audit records to keep}', function () {
    $days = $this->option('days');
    $retentionDays = is_numeric($days) ? (int) $days : (int) config('myapes.audit.retention_days', 180);
    $threshold = now()->subDays($retentionDays);

    $deleted = AuditLog::query()
        ->where('created_at', '<', $threshold)
        ->delete();

    $this->info("Deleted {$deleted} audit log record(s) older than {$retentionDays} days.");
})->purpose('Prune old audit logs using retention policy');

Artisan::command('mail:prune-log {--days= : Number of days of outbound mail metadata to keep}', function () {
    $days = $this->option('days');
    $retentionDays = is_numeric($days) ? (int) $days : (int) config('myapes.mail.log_retention_days', 90);
    $threshold = now()->subDays($retentionDays);

    $deleted = OutboundMailLog::query()
        ->where('created_at', '<', $threshold)
        ->delete();

    $this->info("Deleted {$deleted} outbound mail log record(s) older than {$retentionDays} days.");
})->purpose('Prune outbound mail metadata using the retention policy');

Schedule::command('audit:prune')->daily();
Schedule::command('mail:prune-log')->daily();
Schedule::job(new RunDirectorySync(DirectorySyncRun::SOURCE_SCHEDULED))
    ->hourly()
    ->withoutOverlapping();
