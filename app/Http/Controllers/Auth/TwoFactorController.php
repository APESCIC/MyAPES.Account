<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TotpTwoFactorService;
use App\Support\AccountSecurityRedirect;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TotpTwoFactorService $twoFactor,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->isLocalPasswordIdentity()) {
            abort(403);
        }

        if ($this->twoFactor->hasEnabledTwoFactor($user)) {
            return redirect()->to(AccountSecurityRedirect::security('two-factor'));
        }

        if (! $this->twoFactor->hasPendingEnrolment($user)) {
            return redirect()->to(AccountSecurityRedirect::security('two-factor'));
        }

        $plainCodes = $request->session()->get(TotpTwoFactorService::SESSION_PLAIN_RECOVERY_CODES);

        return view('auth.two-factor-setup', [
            'qrSvg' => $this->twoFactor->qrCodeSvg($user),
            'otpauthUrl' => $this->twoFactor->otpauthUrl($user, (string) $user->two_factor_secret),
            'recoveryCodes' => is_array($plainCodes) ? $plainCodes : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->isLocalPasswordIdentity()) {
            abort(403);
        }

        try {
            $enrolment = $this->twoFactor->beginEnrolment($user);
        } catch (DomainException $exception) {
            return redirect()
                ->to(AccountSecurityRedirect::security('two-factor'))
                ->withErrors(['two_factor' => $exception->getMessage()]);
        }

        $request->session()->flash(
            TotpTwoFactorService::SESSION_PLAIN_RECOVERY_CODES,
            $enrolment['recovery_codes'],
        );

        return redirect()->route('two-factor.setup');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->isLocalPasswordIdentity()) {
            abort(403);
        }

        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        try {
            $this->twoFactor->confirmEnrolment($user, $validated['code']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'code' => $exception->getMessage(),
            ]);
        }

        $request->session()->forget(TotpTwoFactorService::SESSION_PLAIN_RECOVERY_CODES);

        return redirect()
            ->to(AccountSecurityRedirect::security('two-factor'))
            ->with('status', __('flash.two_factor_enabled'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->isLocalPasswordIdentity()) {
            abort(403);
        }

        try {
            $this->twoFactor->disable($user);
        } catch (DomainException $exception) {
            return redirect()
                ->to(AccountSecurityRedirect::security('two-factor'))
                ->withErrors(['two_factor' => $exception->getMessage()]);
        }

        $request->session()->forget(TotpTwoFactorService::SESSION_PLAIN_RECOVERY_CODES);

        return redirect()
            ->to(AccountSecurityRedirect::security('two-factor'))
            ->with('status', __('flash.two_factor_disabled'));
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->isLocalPasswordIdentity()) {
            abort(403);
        }

        try {
            $codes = $this->twoFactor->regenerateRecoveryCodes($user);
        } catch (DomainException $exception) {
            return redirect()
                ->to(AccountSecurityRedirect::security('two-factor'))
                ->withErrors(['two_factor' => $exception->getMessage()]);
        }

        $request->session()->flash(
            TotpTwoFactorService::SESSION_PLAIN_RECOVERY_CODES,
            $codes,
        );

        return redirect()
            ->to(AccountSecurityRedirect::security('two-factor'))
            ->with('status', __('flash.two_factor_recovery_regenerated'));
    }
}
