<?php

namespace App\Http\Controllers\Auth;

use App\Core\Accounts\User;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureRecentStepUp;
use App\Services\SessionAuthorizationContext;
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

        if (! is_string($intended) || $intended === '') {
            return route('profile.edit');
        }

        $intendedPath = parse_url($intended, PHP_URL_PATH);
        $passwordUpdatePath = parse_url(route('profile.password.update'), PHP_URL_PATH);
        $usernameUpdatePath = parse_url(route('profile.username.update'), PHP_URL_PATH);
        $emailChangePath = parse_url(route('profile.email.change'), PHP_URL_PATH);
        $passkeyOptionsPath = parse_url(route('passkey.registration-options'), PHP_URL_PATH);
        $passkeyStorePath = parse_url(route('passkey.store'), PHP_URL_PATH);

        if (is_string($passwordUpdatePath) && $intendedPath === $passwordUpdatePath) {
            return route('profile.edit').'#change-password';
        }

        if (is_string($usernameUpdatePath) && $intendedPath === $usernameUpdatePath) {
            return route('profile.edit').'#change-username';
        }

        if (is_string($emailChangePath) && $intendedPath === $emailChangePath) {
            return route('profile.edit').'#change-email';
        }

        if (
            (is_string($passkeyOptionsPath) && $intendedPath === $passkeyOptionsPath)
            || (is_string($passkeyStorePath) && $intendedPath === $passkeyStorePath)
            || (is_string($intendedPath) && str_starts_with($intendedPath, '/user/passkeys'))
        ) {
            return route('profile.edit').'#passkeys';
        }

        return $intended;
    }
}
