<?php

namespace App\Http\Controllers\Admin;

use App\Core\Mail\Inbound\Diagnostics\MailboxConnection;
use App\Core\Mail\Inbound\Diagnostics\MailboxDiagnostics;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\ReplyMailboxStatus;
use App\Core\Secrets\SecretRegistry;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Admin → Settings → Keys and secrets (#810): write-only set, replace, clear
 * and test for managed secrets. Values are never echoed back.
 */
class AdminKeysAndSecretsController extends Controller
{
    private const TESTS_PER_WINDOW = 5;

    private const TEST_WINDOW_SECONDS = 600;

    public function store(Request $request, SecretRegistry $secrets, AuditLogger $audit): RedirectResponse
    {
        $value = $this->validatedPassword($request);
        $hadStored = $secrets->source(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD) === SecretRegistry::SOURCE_KEYS;
        $secrets->set(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD, $value, (int) $request->user()->id);
        $audit->record($hadStored ? 'admin.settings.secret_replaced' : 'admin.settings.secret_set', $request->user(), context: [
            'key' => SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD,
        ]);

        return $this->back()->with('status', $hadStored
            ? __('admin.settings.keys_and_secrets.replaced')
            : __('admin.settings.keys_and_secrets.saved'));
    }

    public function destroy(Request $request, SecretRegistry $secrets, AuditLogger $audit): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']]);
        $secrets->clear(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD);
        $audit->record('admin.settings.secret_cleared', $request->user(), context: [
            'key' => SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD,
        ]);

        return $this->back()->with('status', __('admin.settings.keys_and_secrets.cleared'));
    }

    /**
     * Tests with the typed value in memory (never stored), or the saved value when the field is blank (#810).
     */
    public function test(
        Request $request,
        SecretRegistry $secrets,
        InboundMailSettings $inbound,
        MailboxDiagnostics $diagnostics,
        ReplyMailboxStatus $status,
        AuditLogger $audit,
    ): RedirectResponse {
        $limiter = 'keys-secret-test:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($limiter, self::TESTS_PER_WINDOW)) {
            return $this->back()->withErrors(['secret' => __('admin.emails.import.test_limited', [
                'count' => self::TESTS_PER_WINDOW,
                'minutes' => max(1, (int) ceil(RateLimiter::availableIn($limiter) / 60)),
            ])]);
        }

        $typed = trim((string) $request->input('password', ''));
        $password = $typed !== '' ? $typed : (string) $secrets->get(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD);

        if ($password === '') {
            return $this->back()->withErrors(['password' => __('admin.settings.keys_and_secrets.password_needed')]);
        }

        if ($mismatch = $inbound->connectionMismatchMessage()) {
            return $this->back()->withErrors(['secret' => $mismatch]);
        }

        $connection = new MailboxConnection(
            host: $inbound->imap('host'),
            port: (int) $inbound->imap('port'),
            encryption: $inbound->imap('encryption') ?: 'ssl',
            validateCert: $inbound->validateCert(),
            username: $inbound->imap('username'),
            password: $password,
            folder: $inbound->imap('folder') ?: 'INBOX',
            timeout: max(1, (int) $inbound->imap('timeout')),
        );

        $report = $diagnostics->run($connection);
        RateLimiter::hit($limiter, self::TEST_WINDOW_SECONDS);
        $status->recordTest($report);
        $audit->record('admin.settings.secret_tested', $request->user(), context: [
            'key' => SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD,
            'result' => $report->failure?->value ?? 'ok',
            'step' => $report->failedStep?->value,
        ]);

        $response = $this->back()->with('mailbox_report', $report->toArray());

        return $report->ok()
            ? $response->with('status', __('admin.settings.keys_and_secrets.test_ok'))
            : $response->withErrors(['secret' => __('admin.emails.import.test_failed', [
                'step' => $report->failedStep?->label(),
                'reason' => $report->failure?->message(),
            ])]);
    }

    private function validatedPassword(Request $request): string
    {
        $request->validate([
            'password' => ['required', 'string', 'max:500'],
        ]);

        return (string) $request->input('password');
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('admin.settings.show', AdminSettingsController::TAB_KEYS_AND_SECRETS);
    }
}
