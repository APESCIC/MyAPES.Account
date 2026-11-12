<?php

namespace App\Http\Controllers\Admin;

use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\Diagnostics\MailboxConnection;
use App\Core\Mail\Inbound\Diagnostics\MailboxDiagnostics;
use App\Core\Mail\Inbound\InboundMailboxSettings;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\ReplyMailboxStatus;
use App\Core\Settings\AppSettings;
use App\Core\Settings\SettingDefinition;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Saves Admin → Settings → Reply mailbox (#735). Fields set in .env are not
 * saved, a blank password keeps the saved one, and the password is never
 * echoed back, logged or audited.
 */
class AdminReplyMailboxController extends Controller
{
    private const TESTS_PER_WINDOW = 5;

    private const TEST_WINDOW_SECONDS = 600;

    public function update(Request $request, AppSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $values = [];
        foreach ($settings->definitions(InboundMailboxSettings::GROUP) as $key => $definition) {
            if ($settings->overriddenByEnv(InboundMailboxSettings::GROUP, $key)) {
                continue;
            }

            $values[$key] = $definition->type === SettingDefinition::BOOLEAN
                ? $request->boolean($key)
                : $request->input($key);
        }

        if (array_key_exists('port', $values) || array_key_exists('encryption', $values)) {
            InboundMailboxSettings::guardPortAndEncryption(
                (int) ($values['port'] ?? $settings->get(InboundMailboxSettings::GROUP, 'port')),
                (string) ($values['encryption'] ?? $settings->get(InboundMailboxSettings::GROUP, 'encryption')),
            );
        }

        $changed = $settings->set(InboundMailboxSettings::GROUP, $values, (int) $request->user()->id);

        if ($changed !== []) {
            $audit->record('admin.settings.mailbox_saved', $request->user(), context: [
                'changed' => array_values(array_diff($changed, ['password'])),
                'password_changed' => in_array('password', $changed, true),
            ]);
        }

        return $this->back()->with('status', $changed === [] ? __('admin.settings.reply_mailbox.no_changes') : __('admin.settings.reply_mailbox.saved'));
    }

    /**
     * Tests the values in the form step by step (#771), before saving and
     * before replies are switched on. A blank password uses the saved one; a
     * typed one is used for this test only and never stored, logged or shown.
     */
    public function test(Request $request, AppSettings $settings, InboundMailSettings $inbound, MailboxDiagnostics $diagnostics, InboundMailbox $mailbox, ReplyMailboxStatus $status, AuditLogger $audit): RedirectResponse
    {
        $limiter = 'mailbox-test:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($limiter, self::TESTS_PER_WINDOW)) {
            return $this->back()->withInput($request->except('password'))->withErrors(['mailbox' => __('admin.emails.import.test_limited', [
                'count' => self::TESTS_PER_WINDOW,
                'minutes' => max(1, (int) ceil(RateLimiter::availableIn($limiter) / 60)),
            ])]);
        }

        $report = $inbound->usesMailpit()
            ? $diagnostics->runMailpit($mailbox)
            : $diagnostics->run($this->connectionFromForm($request, $settings, $inbound));
        RateLimiter::hit($limiter, self::TEST_WINDOW_SECONDS);

        $status->recordTest($report);
        $audit->record('admin.emails.import_tested', $request->user(), context: [
            'result' => $report->failure?->value ?? 'ok',
            'step' => $report->failedStep?->value,
        ]);

        $response = $this->back()->withInput($request->except('password'))->with('mailbox_report', $report->toArray());

        return $report->ok()
            ? $response->with('status', __('admin.emails.import.test_ok'))
            : $response->withErrors(['mailbox' => __('admin.emails.import.test_failed', [
                'step' => $report->failedStep?->label(),
                'reason' => $report->failure?->message(),
            ])]);
    }

    /**
     * Sends one email to communications+probe-…@ (#736); the next poll records whether it arrived and never posts it.
     */
    public function probe(Request $request, InboundMailSettings $settings, ReplyMailboxStatus $status, AuditLogger $audit): RedirectResponse
    {
        if (! $settings->pollingConfigured()) {
            return $this->back()->withErrors(['mailbox' => __('admin.settings.reply_mailbox.test_not_configured')]);
        }

        $address = $status->startProbe();
        Mail::raw(__('admin.settings.reply_mailbox.probe_body'), static function (Message $message) use ($address): void {
            $message->to($address)->subject(__('admin.settings.reply_mailbox.probe_subject'));
        });
        $audit->record('admin.settings.mailbox_probe_sent', $request->user());

        return $this->back()->with('status', __('admin.settings.reply_mailbox.probe_sent'));
    }

    /**
     * The form's values for one test. Fields set in .env, or not sent, keep their current value.
     */
    private function connectionFromForm(Request $request, AppSettings $settings, InboundMailSettings $inbound): MailboxConnection
    {
        $editable = fn (string $key): bool => $request->has($key) && ! $settings->overriddenByEnv(InboundMailboxSettings::GROUP, $key);
        $definitions = $settings->definitions(InboundMailboxSettings::GROUP);

        $rules = [];
        foreach (['host', 'port', 'encryption', 'username', 'folder'] as $key) {
            if ($editable($key)) {
                $rules[$key] = ['required', ...$definitions[$key]->rules];
            }
        }
        $rules['password'] = ['nullable', 'string', 'max:500'];
        $request->validate($rules);

        $value = fn (string $key): string => $editable($key) ? trim((string) $request->input($key)) : $inbound->imap($key);
        $typedPassword = (string) $request->input('password', '');
        InboundMailboxSettings::guardPortAndEncryption((int) $value('port'), $value('encryption') ?: 'ssl');

        return new MailboxConnection(
            host: $value('host'),
            port: (int) $value('port'),
            encryption: $value('encryption') ?: 'ssl',
            validateCert: $editable('validate_cert') ? $request->boolean('validate_cert') : $inbound->validateCert(),
            username: $value('username'),
            password: $typedPassword !== '' && ! $settings->overriddenByEnv(InboundMailboxSettings::GROUP, 'password') ? $typedPassword : $inbound->imap('password'),
            folder: $value('folder') ?: 'INBOX',
            timeout: max(1, (int) $inbound->imap('timeout')),
        );
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('admin.settings.show', AdminSettingsController::TAB_REPLY_MAILBOX);
    }
}
