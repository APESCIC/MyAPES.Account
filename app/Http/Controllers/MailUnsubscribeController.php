<?php

namespace App\Http\Controllers;

use App\Core\Accounts\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * One-click opt-out from Optional staff email (#506).
 */
class MailUnsubscribeController extends Controller
{
    public function __invoke(Request $request, User $user, AuditLogger $auditLogger): View
    {
        $preference = $user->contactPreference()->firstOrCreate([]);

        if ($preference->email !== false) {
            $preference->update(['email' => false]);
        }

        $auditLogger->record(
            'contact_preferences.unsubscribed',
            null,
            $user,
            [
                'via' => 'staff_message_list_unsubscribe',
                'ip_address' => $request->ip(),
            ],
        );

        return view('mail.unsubscribed', ['user' => $user]);
    }
}