<?php

namespace App\Http\Responses\Passkeys;

use App\Core\Accounts\User;
use App\Services\SessionAuthorizationContext;
use App\Support\AccountSecurityRedirect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Contracts\PasskeyConfirmationResponse as PasskeyConfirmationResponseContract;
use Symfony\Component\HttpFoundation\Response;

class PasskeyConfirmationResponse implements PasskeyConfirmationResponseContract
{
    public function __construct(
        private readonly SessionAuthorizationContext $authorizationContext,
    ) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $request->session()->regenerate();
            $this->authorizationContext->recordPassword($request, $user);
            $request->session()->passwordConfirmed();
        }

        $redirect = $this->intendedUrl($request);

        if ($request->wantsJson()) {
            return new JsonResponse([
                'redirect' => $redirect,
            ], 200);
        }

        return redirect()->to($redirect);
    }

    private function intendedUrl(Request $request): string
    {
        $intended = $request->session()->pull('url.intended');

        return AccountSecurityRedirect::fromIntended(is_string($intended) ? $intended : null);
    }
}