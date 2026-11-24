<?php

namespace App\Http\Controllers\Auth;

use App\Core\Accounts\User;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureRecentStepUp;
use App\Services\SessionAuthorizationContext;
use App\Support\AccountSecurityRedirect;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ConfirmPasswordController extends Controller
{
    public function __construct(
        private readonly SessionAuthorizationContext $authorizationContext,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasDirectoryIdentity()) {
            return $this->redirectDirectoryUserToOidcStepUp($request, $user);
        }

        $this->ensureLocalPasswordIdentity($request);

        return view('auth.confirm-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasDirectoryIdentity()) {
            return $this->redirectDirectoryUserToOidcStepUp($request, $user);
        }

        $user = $this->ensureLocalPasswordIdentity($request);

        if (! is_string($user->password) || $user->password === '' || ! Hash::check((string) $request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        $request->session()->regenerate();
        $this->authorizationContext->recordPassword($request, $user);
        $request->session()->passwordConfirmed();

        return redirect()->to($this->intendedUrl($request));
    }

    private function redirectDirectoryUserToOidcStepUp(Request $request, User $user): RedirectResponse
    {
        $request->session()->put(EnsureRecentStepUp::OIDC_STEP_UP_SESSION_KEY, [
            'user_id' => $user->getKey(),
            'oidc_sub' => $user->oidc_sub,
        ]);

        if (! $request->session()->has('url.intended')) {
            $request->session()->put('url.intended', $this->intendedUrl($request));
        }

        return redirect()->route('staff.auth.step-up');
    }

    private function ensureLocalPasswordIdentity(Request $request): User
    {
        $user = $request->user();

        if ($user === null || ! $user->isLocalPasswordIdentity()) {
            abort(403, __('auth.confirm_password.local_only'));
        }

        return $user;
    }

    private function intendedUrl(Request $request): string
    {
        $intended = $request->session()->pull('url.intended');

        return AccountSecurityRedirect::fromIntended(is_string($intended) ? $intended : null);
    }
}