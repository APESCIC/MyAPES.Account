<?php

namespace App\Http\Controllers;

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
    public function __invoke(
        ReleaseHistoryRepository $releases,
        MaintenanceMode $maintenanceMode,
    ): JsonResponse {
        $checks = [
            'database' => $this->databaseIsHealthy() ? 'ok' : 'failed',
            'cache' => $this->cacheIsHealthy() ? 'ok' : 'failed',
            'environment' => $this->environmentIsHealthy() ? 'ok' : 'failed',
        ];
        $isHealthy = ! in_array('failed', $checks, true);

        $body = [
            'status' => $isHealthy ? 'ok' : 'unavailable',
            'version' => $releases->version(),
            'release' => $this->release(),
            'maintenance' => $maintenanceMode->active(),
            'checks' => $checks,
        ];

        // The live preview gate checks the Laragon preview serves this checkout through FastCGI.
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
