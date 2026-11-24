<?php

namespace App\Services;

use App\Core\Accounts\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ends every session except the current browser session (#920).
 */
final class AccountOtherSessionTerminator
{
    public function __construct(
        private readonly SessionAuthorizationContext $authorizationContext,
    ) {}

    public function terminateOtherSessions(Request $request, User $user): void
    {
        $currentSessionId = $request->session()->getId();

        DB::transaction(function () use ($request, $user, $currentSessionId): void {
            $user->forceFill([
                'authorization_epoch' => (int) $user->authorization_epoch + 1,
            ]);
            $user->setRememberToken(Str::random(60));
            $user->save();

            $table = (string) config('session.table', 'sessions');
            if (
                (string) config('session.driver') === 'database'
                && Schema::hasTable($table)
            ) {
                DB::table($table)
                    ->where('user_id', $user->id)
                    ->where('id', '!=', $currentSessionId)
                    ->delete();
            }
        });

        $method = $this->authorizationContext->authenticationMethod($request)
            ?? SessionAuthorizationContext::METHOD_PASSWORD;

        if ($method === SessionAuthorizationContext::METHOD_CLOUDRON_OIDC) {
            $this->authorizationContext->recordCloudronOidc($request, $user);
        } elseif ($method === SessionAuthorizationContext::METHOD_QA) {
            $this->authorizationContext->recordQa($request, $user);
        } else {
            $this->authorizationContext->recordPassword($request, $user);
        }
    }
}