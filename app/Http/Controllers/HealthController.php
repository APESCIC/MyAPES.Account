<?php

namespace App\Http\Controllers;

use App\Core\Mail\Inbound\ImportAlerts;
use App\Core\Webhooks\WebhookDelivery;
use App\Support\LocalRevision;
use App\Support\ReleaseHistoryRepository;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class HealthController extends Controller
{
    private const QUEUE_GRACE_MINUTES = 15;

    public function __invoke(
        ReleaseHistoryRepository $releases,
        MaintenanceMode $maintenanceMode,
        ImportAlerts $alerts,
    ): JsonResponse {
        $checks = [
            'database' => $this->databaseIsHealthy() ? 'ok' : 'failed',
            'cache' => $this->cacheIsHealthy() ? 'ok' : 'failed',
            'environment' => $this->environmentIsHealthy() ? 'ok' : 'failed',
        ];
        $isHealthy = ! in_array('failed', $checks, true);
        // Reported, not fatal (#644): a webhook backlog must not fail a deploy or take the site down.
        $checks['queue'] = $this->queueState();
        // Also reported, not fatal (#779): only ok, failing or off, never a host, user or error.
        $checks['inbound_mail'] = $this->inboundMailState($alerts);

        $body = [
            'status' => $isHealthy ? 'ok' : 'unavailable',
            'version' => $releases->version(),
            'release' => $this->release(),
            'maintenance' => $maintenanceMode->active(),
            'checks' => $checks,
        ];

        // The live preview gate checks the Herd preview serves this checkout through FastCGI.
        if (app()->environment('local')) {
            $body['local'] = [
                'php' => PHP_VERSION,
                'php_sapi' => PHP_SAPI,
                'git_revision' => (new LocalRevision(base_path()))->current(),
            ];
        }

        return response()->json($body, $isHealthy ? 200 : 503);
    }

    private function databaseIsHealthy(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function cacheIsHealthy(): bool
    {
        $key = 'health:'.Str::uuid();

        try {
            Cache::put($key, 'ok', 10);

            return Cache::pull($key) === 'ok';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * "ok" while queued webhook deliveries are picked up on time, "delayed" when
     * one has waited more than 15 minutes past its due time (the queue worker
     * is likely stopped), "unknown" when the database cannot be asked.
     */
    private function queueState(): string
    {
        try {
            $overdue = WebhookDelivery::query()
                ->whereIn('status', [WebhookDelivery::PENDING, WebhookDelivery::RETRYING])
                ->where(static fn ($query) => $query
                    ->where('next_attempt_at', '<', now()->subMinutes(self::QUEUE_GRACE_MINUTES))
                    ->orWhere(static fn ($pending) => $pending->whereNull('next_attempt_at')->where('created_at', '<', now()->subMinutes(self::QUEUE_GRACE_MINUTES))))
                ->exists();
        } catch (Throwable) {
            return 'unknown';
        }

        return $overdue ? 'delayed' : 'ok';
    }

    private function inboundMailState(ImportAlerts $alerts): string
    {
        try {
            return $alerts->health();
        } catch (Throwable) {
            return ImportAlerts::HEALTH_OFF;
        }
    }

    private function environmentIsHealthy(): bool
    {
        return ! is_file(base_path('REVISION'))
            || app()->environment('production');
    }

    private function release(): string
    {
        $revisionPath = base_path('REVISION');

        if (! is_file($revisionPath)) {
            return app()->environment('production') ? 'unknown' : 'development';
        }

        $release = trim((string) file_get_contents($revisionPath));

        return preg_match('/\A[0-9a-f]{7,40}\z/i', $release) === 1 ? $release : 'unknown';
    }
}
