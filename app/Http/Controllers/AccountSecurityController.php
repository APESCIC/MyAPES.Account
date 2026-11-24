<?php

namespace App\Http\Controllers;

use App\Services\AccountOtherSessionTerminator;
use App\Services\AuditLogger;
use App\Support\AccountSecurityRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountSecurityController extends Controller
{
    public function destroyOtherSessions(
        Request $request,
        AccountOtherSessionTerminator $terminator,
        AuditLogger $audit,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $terminator->terminateOtherSessions($request, $user);
        $audit->record('auth.other_sessions_revoked', $user, $user);

        return redirect()
            ->to(AccountSecurityRedirect::security('signed-in-devices'))
            ->with('status', __('account.settings.security_devices.signed_out_others'));
    }
}