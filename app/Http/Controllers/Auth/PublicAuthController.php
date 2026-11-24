<?php

namespace App\Http\Controllers\Auth;

use App\Core\Accounts\User;
use App\Core\BotProtection\BotProtection;
use App\Http\Controllers\Controller;
use App\Rules\AvailablePublicEmail;
use App\Rules\NotStaffEmail;
use App\Rules\Username;
use App\Services\AuditLogger;
use App\Services\AuthorizationAccountSynchronizer;
use App\Services\AuthorizationProfile;
use App\Services\SessionAuthorizationContext;
use App\Services\TotpTwoFactorService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class PublicAuthController extends Controller
{
    public function __construct(
        private readonly AuthorizationProfile $authorizationProfile,
        private readonly AuthorizationAccountSynchronizer $accounts,
        private readonly SessionAuthorizationContext $authorizationContext,
    ) {}

    public function showLogin(Request $request, AuditLogger $auditLogger): View|RedirectResponse
    {
        return view('auth.public-login');
    }

    public function login(Request $request, AuditLogger $auditLogger, BotProtection $botProtection): RedirectResponse
    {
        $botProtection->check($request, BotProtection::LOGIN);
        $credentialField = $request->filled('login') ? 'login' : 'email';
        if ($credentialField === 'email') {
            $request->merge(['login' => $request->input('email')]);
        }

        $validated = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $login = Str::lower(trim($validated['login']));
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$login])
            ->orWhere('username', $login)
            ->first();

        if ($user === null || ! is_string($user->password) || ! Hash::check($validated['password'], $user->password)) {
            $auditLogger->record('auth.public_login_failed', null, null, [
                'reason' => 'invalid_credentials',
            ]);
            $botProtection->recordLoginFailure($request);

            return back()
                ->withErrors([$credentialField => __('auth.failed')])
                ->onlyInput($credentialField);
        }

        if ($user->suspended_at !== null) {
            $auditLogger->record(
                'auth.suspended_login_denied',
                $user,
                $user,
                [
                    'method' => SessionAuthorizationContext::METHOD_PASSWORD,
                    'redirect' => route('public.login'),
                    'reason' => 'This account is suspended.',
                ],
            );

            return redirect()
                ->route('public.login')
                ->withErrors([$credentialField => 'This account is suspended.']);
        }

        if ($user->isProfileOnly()) {
            $auditLogger->record(
                'auth.profile_only_login_denied',
                $user,
                $user,
                [
                    'method' => SessionAuthorizationContext::METHOD_PASSWORD,
                    'redirect' => route('public.login'),
                    'reason' => 'profile_only',
                ],
            );

            return redirect()
                ->route('public.login')
                ->withErrors([$credentialField => __('auth.profile_only')]);
        }

        if ($user->identity_type !== User::IDENTITY_LOCAL
            || $this->authorizationProfile->hasDirectoryProtectedEligibility($user)) {
            $auditLogger->record('auth.public_login_blocked_for_staff', $user, $user);

            return redirect()
                ->route('staff.login')
                ->withErrors([$credentialField => 'Staff and directory accounts must sign in using Staff Login.']);
        }

        if ($user->hasEnabledTwoFactor()) {
            $request->session()->put([
                TotpTwoFactorService::SESSION_LOGIN_ID => $user->id,
                TotpTwoFactorService::SESSION_LOGIN_REMEMBER => $request->boolean('remember'),
            ]);

            $auditLogger->record('auth.two_factor_challenge_required', $user, $user);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $this->authorizationContext->recordPassword($request, $user);
        $botProtection->clearLoginFailures($request);
        $auditLogger->record('auth.public_login_success', $user, $user);

        return redirect()->intended(route('dashboard'));
    }

    public function showRegister(): View
    {
        return view('auth.public-register');
    }

    public function register(Request $request, AuditLogger $auditLogger, BotProtection $botProtection): RedirectResponse
    {
        $botProtection->check($request, BotProtection::REGISTER);
        $request->merge([
            'username' => Str::lower(trim((string) $request->input('username'))),
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', new Username],
            'email' => ['required', 'string', 'email', 'max:255', new NotStaffEmail, new AvailablePublicEmail],
            'password' => ['required', 'confirmed', Password::defaults()],
            'services' => ['required', 'array', 'min:1'],
            'services.*' => [
                'string',
                Rule::in(['apes-cic', 'shelter-rescue', 'pet-care-clinic']),
            ],
            'registration_consent' => ['accepted'],
        ], [
            'registration_consent.accepted' => __('auth.register.consent_required'),
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $user = new User([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'identity_type' => User::IDENTITY_LOCAL,
                'account_state' => User::ACCOUNT_STATE_ACTIVE,
                'email_verified_at' => null,
            ]);
            $user->registration_consented_at = now();
            $user->save();
            $this->accounts->grantPublicBaseline($user);
            $user->contactPreference()->create([]);

            foreach ($validated['services'] as $service) {
                $user->serviceSelections()->create(['sub_core_key' => $service]);
            }

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();
        $this->authorizationContext->recordPassword($request, $user);
        $auditLogger->record('auth.public_registration_success', $user, $user);
        $user->sendEmailVerificationNotification();

        return redirect()->route('verification.notice');
    }

    public function localStaffLogin(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        if (! app()->environment(['local', 'testing'])) {
            abort(404);
        }

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, false)) {
            $auditLogger->record('auth.local_staff_login_failed', null, null, [
                'email' => $credentials['email'],
            ]);

            return back()
                ->withErrors(['email' => 'The provided credentials do not match our records.'])
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = $request->user();
        if ($user->suspended_at !== null) {
            $this->denyAuthenticatedLogin(
                $request,
                $user,
                $auditLogger,
                'auth.suspended_login_denied',
                route('staff.login'),
                'This account is suspended.',
                SessionAuthorizationContext::METHOD_QA,
            );

            return redirect()
                ->route('staff.login')
                ->withErrors(['email' => 'This account is suspended.']);
        }

        if (! $this->authorizationProfile->hasDirectoryProtectedEligibility($user)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $auditLogger->record('auth.local_staff_login_denied_for_non_staff', $user, $user);

            return redirect()
                ->route('staff.login')
                ->withErrors(['email' => 'This local staff login is only for staff/admin accounts.']);
        }

        $this->authorizationContext->recordQa($request, $user);
        $auditLogger->record('auth.local_staff_login_success', $user, $user, [
            'role' => $this->authorizationProfile->displayKey($user),
        ]);

        return redirect()->intended(route('dashboard'));
    }

    private function denyAuthenticatedLogin(
        Request $request,
        User $user,
        AuditLogger $auditLogger,
        string $event,
        string $redirect,
        string $message,
        string $method,
    ): void {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $auditLogger->record($event, $user, $user, [
            'method' => $method,
            'redirect' => $redirect,
            'reason' => $message,
        ]);
    }
}
