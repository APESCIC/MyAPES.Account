<?php

namespace App\Http\Controllers\Admin;

use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\InboundMailboxSettings;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\MailboxUnavailable;
use App\Core\Mail\Inbound\ReplyMailboxStatus;
use App\Core\Settings\AppSettings;
use App\Core\Settings\SettingDefinition;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

/**
 * Saves Admin → Settings → Reply mailbox (#735). Fields set in .env are not
 * saved, a blank password keeps the saved one, and the password is never
 * echoed back, logged or audited.
 */
class AdminReplyMailboxController extends Controller
{
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
     * Logs in to the mailbox and opens the folder (#736). Errors name the failure type only.
     */
    public function test(Request $request, InboundMailSettings $settings, InboundMailbox $mailbox, ReplyMailboxStatus $status, AuditLogger $audit): RedirectResponse
    {
        if (! $settings->pollingConfigured()) {
            return $this->back()->withErrors(['mailbox' => __('admin.settings.reply_mailbox.test_not_configured')]);
        }

        try {
            $mailbox->test();
            $error = null;
        } catch (MailboxUnavailable $exception) {
            $error = $exception->getMessage();
        }

        $status->recordTest($error === null, $error);
        $audit->record('admin.settings.mailbox_tested', $request->user(), context: [
            'result' => $error === null ? 'ok' : 'failed',
            'driver' => $settings->driver(),
        ]);

        return $error === null
            ? $this->back()->with('status', __('admin.settings.reply_mailbox.test_ok'))
            : $this->back()->withErrors(['mailbox' => __('admin.settings.reply_mailbox.test_failed', ['reason' => $error])]);
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

    private function back(): RedirectResponse
    {
        return redirect()->route('admin.settings.show', AdminSettingsController::TAB_REPLY_MAILBOX);
    }
}
