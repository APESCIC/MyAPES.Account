<?php

namespace App\Http\Controllers\Auth;

use App\Core\Accounts\User;
use App\Core\BotProtection\BotProtection;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\LocalPublicPasswordResetService;
use App\Services\SessionAuthorizationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PublicPasswordResetController extends Controller
{
    public const REQUEST_STATUS_KEY = 'passwords.request_status';

    public function __construct(
        private readonly LocalPublicPasswordResetService $resets,
        private readonly SessionAuthorizationContext $authorizationContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request, BotProtection $botProtection): RedirectResponse
    {
        $botProtection->check($request, BotProtection::FORGOT_PASSWORD);
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = Str::lower(trim($validated['email']));
        $user = User::query()->where('email', $email)->first();

        if ($user !== null && $this->resets->isEligibleForGuestReset($user)) {
            Password::sendResetLink(['email' => $email]);
            $this->auditLogger->record(
                'auth.public_local_password_reset_requested',
                null,
                $user,
                [
                    'target_user_id' => $user->id,
                ],
            );
        } elseif ($user !== null) {
            $this->auditLogger->record(
                'auth.public_local_password_reset_refused',
                null,
                $user,
                [
                    'target_user_id' => $user->id,
                    'reason' => 'not_local_public',
                ],
            );
        }

        return back()->with('status', __(self::REQUEST_STATUS_KEY));
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $email = Str::lower(trim($validated['email']));
        $user = User::query()->where('email', $email)->first();

        if (! $this->resets->isEligibleForGuestReset($user)) {
            throw ValidationException::withMessages([
                'email' => __('passwords.token'),
            ]);
        }

        $status = Password::reset(
            [
                'email' => $email,
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $validated['token'],
            ],
            function (User $resetUser, string $password) use ($request): void {
                $this->resets->completeGuestReset($resetUser, $password);
                Auth::login($resetUser);
                $request->session()->regenerate();
                $this->authorizationContext->recordPassword($request, $resetUser);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }

        return redirect()->intended(route('dashboard'));
    }
}
