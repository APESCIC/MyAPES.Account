<?php

namespace App\Http\Controllers;

use App\Core\Accounts\User;
use App\Services\AuditLogger;
use App\Services\ContactPreferenceUpdater;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * One-click opt-out from Optional staff email (#506).
 *
 * GET shows a confirmation page (non-mutating). POST applies the opt-out for
 * RFC 8058 List-Unsubscribe=One-Click and the confirmation form.
 */
class MailUnsubscribeController extends Controller
{
    public function show(User $user): View
    {
        return view('mail.unsubscribe-confirm', ['user' => $user]);
    }

    public function store(
        Request $request,
        User $user,
        ContactPreferenceUpdater $preferences,
        AuditLogger $auditLogger,
    ): View {
        $current = $user->contactPreference()->firstOrCreate([]);

        $choices = [];
        foreach (ContactPreferenceUpdater::CHANNELS as $channel) {
            $choices[$channel] = $channel === 'email' ? false : (bool) $current->{$channel};
        }

        $preferences->update(
            $user,
            $user,
            $choices,
            'staff_message_list_unsubscribe',
        );

        $auditLogger->record(
            'contact_preferences.unsubscribed',
            null,
            $user,
            [
                'via' => 'staff_message_list_unsubscribe',
                'ip_address' => $request->ip(),
                'one_click' => $request->input('List-Unsubscribe') === 'One-Click'
                    || $request->isMethod('post'),
            ],
        );

        return view('mail.unsubscribed', ['user' => $user]);
    }
}
