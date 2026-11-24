<?php

namespace App\Http\Middleware;

use App\Services\AccountSettingsViewData;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate passkey enrolment/management to local password identities (#232)
 * and directory-linked staff accounts (#922).
 */
class EnsureLocalPasskeyIdentity
{
    public function __construct(
        private readonly AccountSettingsViewData $accountSettings,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(403, __('auth.passkeys.local_only'));
        }

        if (
            $user->isLocalPasswordIdentity()
            || $this->accountSettings->canManageDirectoryStaffCredentials($user)
        ) {
            return $next($request);
        }

        abort(403, __('auth.passkeys.local_only'));
    }
}
