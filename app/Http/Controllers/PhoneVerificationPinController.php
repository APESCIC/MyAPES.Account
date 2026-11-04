<?php

namespace App\Http\Controllers;

use App\Core\Accounts\PhoneVerificationPinService;
use App\Services\AuditLogger;
use App\Services\AuthorizationProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Users set or change their phone verification PIN (#476). The route sits
 * behind account.step-up, so changing it needs a recent re-authentication.
 */
class PhoneVerificationPinController extends Controller
{
    public function update(Request $request, PhoneVerificationPinService $pins, AuditLogger $audit, AuthorizationProfile $profile): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isLocalPasswordIdentity() && ! $profile->hasDirectoryProtectedEligibility($user), 403);

        $length = PhoneVerificationPinService::MIN_LENGTH.','.PhoneVerificationPinService::MAX_LENGTH;
        $validated = $request->validate([
            'pin' => ['required', 'string', 'regex:/^\d{'.$length.'}$/', 'confirmed'],
        ], [
            'pin.regex' => __('public.phone_pin.invalid', ['min' => PhoneVerificationPinService::MIN_LENGTH, 'max' => PhoneVerificationPinService::MAX_LENGTH]),
        ]);

        $changing = $pins->for($user) !== null;
        $pins->set($user, $validated['pin']);
        $audit->record($changing ? 'profile.phone_pin_changed' : 'profile.phone_pin_set', $user, $user);

        return redirect()
            ->to(route('profile.edit').'#phone-pin')
            ->with('status', __($changing ? 'public.phone_pin.changed' : 'public.phone_pin.saved'));
    }
}
