<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Rules\NotContainingPersonalIdentifiers;
use App\Services\LocalPublicPasswordResetService;
use App\Services\TemporaryPasswordService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class ForcedPasswordChangeController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null && $user->mustChangePassword(), 403);

        return view('auth.forced-password-change', [
            'user' => $user,
        ]);
    }

    public function update(
        Request $request,
        TemporaryPasswordService $temporaryPasswords,
        LocalPublicPasswordResetService $passwordResets,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user !== null && $user->mustChangePassword(), 403);

        if ($temporaryPasswords->isTemporaryPasswordExpired($user)) {
            $temporaryPasswords->recordExpiredAttempt($user);
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('public.login')
                ->withErrors(['login' => __('auth.temporary_password_unavailable')]);
        }

        $validated = $request->validate([
            'password' => [
                'required',
                'string',
                'confirmed',
                PasswordRule::defaults(),
                new NotContainingPersonalIdentifiers($user),
            ],
        ]);

        if ($temporaryPasswords->matchesCurrentPassword($user, $validated['password'])) {
            throw ValidationException::withMessages([
                'password' => __('auth.forced_password.not_temporary'),
            ]);
        }

        $passwordResets->changeOwnPassword($user, $validated['password']);

        return redirect()
            ->route('dashboard')
            ->with('status', __('auth.forced_password.success'));
    }
}
