<?php

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\CallLog;
use App\Core\Accounts\DirectorySyncRun;
use App\Core\Mail\OutboundMailLog;
use App\Jobs\RunDirectorySync;
use App\Services\AuditLogger;
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

Artisan::command('call-logs:prune {--months= : Number of months of call logs to keep}', function () {
    $months = $this->option('months');
    $retentionMonths = is_numeric($months) ? (int) $months : (int) config('myapes.call_logs.retention_months', 24);

    if ($retentionMonths < 1) {
        $this->error('Call log retention must be at least 1 month.');

        return 1;
    }

    $deleted = CallLog::query()
        ->where('called_at', '<', now()->subMonths($retentionMonths))
        ->delete();

    if ($deleted > 0) {
        app(AuditLogger::class)->record('call_logs.pruned', context: [
            'deleted' => $deleted,
            'retention_months' => $retentionMonths,
        ]);
    }

    $this->info("Deleted {$deleted} call log record(s) older than {$retentionMonths} months.");

    return 0;
})->purpose('Remove call logs older than the UK GDPR retention period');

Schedule::command('audit:prune')->daily();
Schedule::command('call-logs:prune')->daily();
Schedule::command('mail:prune-log')->daily();
Schedule::command('tickets:inbound-poll')->everyMinute()->withoutOverlapping(10);
Schedule::command('inbound-mail:prune')->daily();
Schedule::command('inbound-mail:empty-folders')->daily();
Schedule::job(new RunDirectorySync(DirectorySyncRun::SOURCE_SCHEDULED))
    ->hourly()
    ->withoutOverlapping();
