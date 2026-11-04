<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Receives browser CSP violation reports (#485) so the report-only run can
 * show what would break before the policy is enforced.
 */
class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $payload = json_decode((string) $request->getContent(), true);
        $report = is_array($payload) ? ($payload['csp-report'] ?? $payload) : [];

        if (is_array($report)) {
            Log::warning('csp.violation', [
                'directive' => Str::limit((string) ($report['violated-directive'] ?? $report['effectiveDirective'] ?? ''), 120),
                'blocked' => Str::limit((string) ($report['blocked-uri'] ?? $report['blockedURL'] ?? ''), 200),
                'page' => Str::limit((string) parse_url((string) ($report['document-uri'] ?? $report['documentURL'] ?? ''), PHP_URL_PATH), 200),
            ]);
        }

        return response()->noContent();
    }
}
