<?php

namespace App\Http\Controllers\Admin;

use App\Core\Mail\Inbound\InboundMailboxSettings;
use App\Core\Settings\AppSettings;
use App\Core\Settings\SettingDefinition;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

        return redirect()
            ->route('admin.settings.show', AdminSettingsController::TAB_REPLY_MAILBOX)
            ->with('status', $changed === [] ? __('admin.settings.reply_mailbox.no_changes') : __('admin.settings.reply_mailbox.saved'));
    }
}
