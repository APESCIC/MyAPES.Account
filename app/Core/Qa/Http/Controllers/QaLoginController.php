<?php

namespace App\Core\Qa\Http\Controllers;

use App\Core\Qa\QaLogin;
use App\Services\AuditLogger;
use App\Services\SessionAuthorizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class QaLoginController
{
    public function __construct(
        private readonly SessionAuthorizationContext $authorizationContext,
    ) {}

    public function index(Request $request): View
    {
        return view('qa.index', [
            'qaProfiles' => QaLogin::picker(),
            'current' => $request->user(),
            'currentProfile' => QaLogin::profileFor($request->user()),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'profile' => ['required', 'string', Rule::in(array_keys(QaLogin::profiles()))],
            'return_to' => ['nullable', 'string', 'max:2000'],
        ]);

        $previous = QaLogin::profileFor($request->user());
        $user = QaLogin::userFor($validated['profile']);

        if ($user->suspended_at !== null) {
            $audit->record(
                'auth.suspended_login_denied',
                $user,
                $user,
                [
                    'entry' => 'qa_login',
                    'method' => SessionAuthorizationContext::METHOD_QA,
                ],
            );

            return back()->withErrors([
                'profile' => __('qa.errors.suspended'),
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $this->authorizationContext->recordQa($request, $user);
        $request->session()->passwordConfirmed();

        $audit->record('auth.signed_in', $user, $user, array_filter([
            'method' => SessionAuthorizationContext::METHOD_QA,
            'profile' => $validated['profile'],
            'switched_from' => $previous,
        ], static fn ($value) => $value !== null));

        $returnTo = QaLogin::safeReturnPath($validated['return_to'] ?? null);
        if ($returnTo !== null || $request->session()->has('url.intended')) {
            $request->session()->flash(QaLogin::SWITCHED, true);
        }

        return redirect()->intended($returnTo ?? route('dashboard'));
    }

    public function destroy(Request $request, AuditLogger $audit): RedirectResponse
    {
        $profile = QaLogin::profileFor($request->user());
        $audit->record('auth.signed_out', $request->user(), $request->user(), array_filter([
            'method' => SessionAuthorizationContext::METHOD_QA,
            'profile' => $profile,
        ], static fn ($value) => $value !== null));

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(route('public.login').'#developer-signin')
            ->with('status', __('qa.status.signed_out'));
    }
}
