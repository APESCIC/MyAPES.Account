<?php

namespace App\Http\Controllers\Auth;

use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\User;
use App\Http\Controllers\Controller;
use App\Rules\AvailablePublicEmail;
use App\Rules\NotStaffEmail;
use App\Services\SecureEmailChangeService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmailChangeController extends Controller
{
    public function __construct(
        private readonly SecureEmailChangeService $emailChanges,
        private readonly AccountClassifier $classifier,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->isLocalPasswordIdentity()) {
            abort(403);
        }

        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::notIn([strtolower(trim((string) $user->email))]),
                ...($this->classifier->isStaffSide($user) ? [] : [new NotStaffEmail]),
                new AvailablePublicEmail,
            ],
        ], [
            'email.not_in' => __('auth.email_change.same_as_current'),
        ]);

        try {
            $this->emailChanges->start($user, $validated['email']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'email' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('profile.edit')
            ->with('status', __('flash.email_change_started'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->isLocalPasswordIdentity()) {
            abort(403);
        }

        try {
            $this->emailChanges->cancel($user);
        } catch (DomainException $exception) {
            return redirect()
                ->route('profile.edit')
                ->withErrors(['email' => $exception->getMessage()]);
        }

        return redirect()
            ->route('profile.edit')
            ->with('status', __('flash.email_change_cancelled'));
    }

    public function confirm(Request $request, User $user, string $hash): RedirectResponse
    {
        try {
            $this->emailChanges->confirm($user, $hash);
        } catch (DomainException $exception) {
            $fallback = $request->user() ? 'profile.edit' : 'public.login';

            return redirect()
                ->route($fallback)
                ->withErrors(['email' => $exception->getMessage()]);
        }

        if ($request->user()?->is($user)) {
            return redirect()
                ->route('profile.edit')
                ->with('status', __('flash.email_change_completed'));
        }

        return redirect()
            ->route('public.login')
            ->with('status', __('flash.email_change_completed'));
    }
}
