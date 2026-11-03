<?php

namespace App\Http\Controllers;

use App\Services\ContactPreferenceUpdater;
use App\Services\UkPhoneNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OnboardingController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return view('profile.onboarding', [
            'profile' => $request->user()->profile,
            'preference' => $request->user()->contactPreference,
            'selectedServices' => $request->user()->serviceSelections()->pluck('sub_core_key')->all(),
        ]);
    }

    public function update(
        Request $request,
        ContactPreferenceUpdater $preferences,
        UkPhoneNumber $phones,
    ): RedirectResponse {
        $this->normalizePhones($request, $phones);
        $validated = $this->validateAccountPreferences($request);
        $user = $request->user();

        DB::transaction(function () use ($user, $validated, $request, $preferences): void {
            $user->profile()->updateOrCreate(['user_id' => $user->id], $this->profilePayload($validated));
            $user->serviceSelections()->whereNotIn('sub_core_key', $validated['services'])->delete();

            foreach ($validated['services'] as $service) {
                $user->serviceSelections()->firstOrCreate(['sub_core_key' => $service]);
            }

            $preferences->update($user, $user, $this->contactChoices($request), 'onboarding');
            $user->forceFill(['onboarding_completed_at' => now()])->save();
        });

        return redirect()->intended(route('dashboard'))->with('status', __('public.onboarding.your_account_setup_is_complete'));
    }

    /** @return array<string, mixed> */
    public function validateAccountPreferences(Request $request): array
    {
        return $request->validate([
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'town_city' => ['required', 'string', 'max:255'],
            'county' => ['nullable', 'string', 'max:255'],
            'postcode' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z]{1,2}\d[A-Za-z\d]?\s*\d[A-Za-z]{2}$/'],
            'mobile_number' => ['required', 'string', 'max:32', 'regex:/^\+44\d{9,10}$/'],
            'landline_number' => ['nullable', 'string', 'max:32', 'regex:/^\+44\d{9,10}$/'],
            'whatsapp_number' => ['nullable', 'string', 'max:32', 'regex:/^\+44\d{9,10}$/'],
            'telegram_username' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]{5,32}$/'],
            'services' => ['required', 'array', 'min:1'],
            'services.*' => ['string', Rule::in(['apes-cic', 'shelter-rescue', 'pet-care-clinic'])],
            'contact_preferences_confirmed' => ['accepted'],
        ]);
    }

    /** @param array<string, mixed> $validated @return array<string, mixed> */
    public function profilePayload(array $validated): array
    {
        return [
            'address_line_1' => $validated['address_line_1'],
            'address_line_2' => $validated['address_line_2'] ?? null,
            'town_city' => $validated['town_city'],
            'county' => $validated['county'] ?? null,
            'postcode' => strtoupper(preg_replace('/\s+/', ' ', trim($validated['postcode']))),
            'country' => 'GB',
            'mobile_number' => $validated['mobile_number'],
            'phone' => $validated['mobile_number'],
            'landline_number' => $validated['landline_number'] ?? null,
            'whatsapp_number' => $validated['whatsapp_number'] ?? null,
            'telegram_username' => isset($validated['telegram_username'])
                ? ltrim($validated['telegram_username'], '@')
                : null,
        ];
    }

    /** @return array<string, bool> */
    public function contactChoices(Request $request): array
    {
        return [
            'calls' => $request->boolean('contact_calls'),
            'sms' => $request->boolean('contact_sms'),
            'whatsapp' => $request->boolean('contact_whatsapp'),
            'telegram' => $request->boolean('contact_telegram'),
            'email' => $request->boolean('contact_email'),
        ];
    }

    public function normalizePhones(Request $request, UkPhoneNumber $phones): void
    {
        $request->merge([
            'mobile_number' => $phones->normalize($request->input('mobile_number'), 'mobile_number'),
            'landline_number' => $phones->normalize($request->input('landline_number'), 'landline_number', false),
            'whatsapp_number' => $phones->normalize($request->input('whatsapp_number'), 'whatsapp_number', false),
            'telegram_username' => is_string($request->input('telegram_username'))
                ? ltrim(trim($request->input('telegram_username')), '@')
                : $request->input('telegram_username'),
        ]);
    }
}
