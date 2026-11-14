<?php

namespace App\Http\Controllers\Admin;

use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\Diagnostics\MailboxConnection;
use App\Core\Mail\Inbound\Diagnostics\MailboxDiagnostics;
use App\Core\Mail\Inbound\InboundMailboxLogEntry;
use App\Core\Mail\Inbound\InboundMailPoller;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\ReplyMailboxStatus;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Admin → Emails → Import (#774, #809): connection is built in; the form is
 * gone. Test and probe still run here against the resolved connection (#812).
 */
class AdminEmailImportController extends Controller
{
    private const TESTS_PER_WINDOW = 5;

    private const TEST_WINDOW_SECONDS = 600;

    public function index(Request $request, EmailImportPage $page): Response
    {
        return response()
            ->view('admin.emails.import.index', $page->viewData($request))
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Tests the built-in connection step by step (#771, #812).
     */
    public function test(Request $request, InboundMailSettings $inbound, MailboxDiagnostics $diagnostics, InboundMailbox $mailbox, ReplyMailboxStatus $status, AuditLogger $audit): RedirectResponse
    {
        $limiter = 'mailbox-test:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($limiter, self::TESTS_PER_WINDOW)) {
            return $this->back()->withErrors(['mailbox' => __('admin.emails.import.test_limited', [
                'count' => self::TESTS_PER_WINDOW,
                'minutes' => max(1, (int) ceil(RateLimiter::availableIn($limiter) / 60)),
            ])]);
        }

        if ($mismatch = $inbound->connectionMismatchMessage()) {
            return $this->back()->withErrors(['mailbox' => $mismatch]);
        }

        $connection = $inbound->usesMailpit() ? null : MailboxConnection::fromSettings($inbound);
        if ($connection !== null && $connection->password() === '') {
            return $this->back()->withErrors(['mailbox' => __('admin.emails.import.password_needed')]);
        }

        $report = $connection === null ? $diagnostics->runMailpit($mailbox) : $diagnostics->run($connection);
        RateLimiter::hit($limiter, self::TEST_WINDOW_SECONDS);

        $status->recordTest($report);
        InboundMailboxLogEntry::recordTest($report);
        $audit->record('admin.emails.import_tested', $request->user(), context: [
            'result' => $report->failure?->value ?? 'ok',
            'step' => $report->failedStep?->value,
            'host' => $connection?->host,
        ]);

        $response = $this->back()->with('mailbox_report', $report->toArray());

        return $report->ok()
            ? $response->with('status', __('admin.emails.import.test_ok'))
            : $response->withErrors(['mailbox' => __('admin.emails.import.test_failed', [
                'step' => $report->failedStep?->label(),
                'reason' => $report->failure?->message(),
            ])]);
    }

    /**
     * Sends one email to communications+probe-…@ (#736, #812); the next poll records whether it arrived and never posts it.
     */
    public function probe(Request $request, InboundMailSettings $settings, ReplyMailboxStatus $status, AuditLogger $audit): RedirectResponse
    {
        if (! $settings->pollingConfigured()) {
            return $this->back()->withErrors(['mailbox' => __('admin.emails.import.test_not_configured')]);
        }

        $address = $status->startProbe();
        Mail::raw(__('admin.emails.import.probe_body'), static function (Message $message) use ($address): void {
            $message->to($address)->subject(__('admin.emails.import.probe_subject'));
        });
        $audit->record('admin.settings.mailbox_probe_sent', $request->user());

        return $this->back()->with('status', __('admin.emails.import.probe_sent'));
    }

    /**
     * Runs one poll now, sharing the poll lock with the scheduler (#812).
     */
    public function fetch(Request $request, InboundMailPoller $poller, AuditLogger $audit): RedirectResponse
    {
        $result = $poller->poll(force: true);
        $audit->record('admin.emails.import_fetched', $request->user(), context: [
            'result' => $result['result'],
            'fetched' => $result['fetched'] ?? 0,
            'stored' => $result['stored'] ?? 0,
        ]);

        if ($result['result'] !== InboundMailPoller::RESULT_POLLED) {
            return $this->back()->withErrors(['mailbox' => match ($result['result']) {
                InboundMailPoller::RESULT_DISABLED => __('admin.emails.import.fetch_disabled'),
                InboundMailPoller::RESULT_LOCKED => __('admin.emails.import.fetch_busy'),
                default => __('admin.emails.import.fetch_unavailable'),
            }]);
        }

        return $this->back()->with('status', __('admin.emails.import.fetched', [
            'fetched' => $result['fetched'],
            'stored' => $result['stored'],
            'duplicates' => $result['duplicates'],
            'failed' => $result['failed'],
        ]));
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('admin.emails.import.index');
    }
}
