<label for="address_line_1">{{ __('public.profile._account-fields.blade.address_line_1') }}</label>
<input id="address_line_1" name="address_line_1" value="{{ old('address_line_1', $profile?->address_line_1) }}" required>
<label for="address_line_2">{{ __('public.profile._account-fields.blade.address_line_2') }}</label>
<input id="address_line_2" name="address_line_2" value="{{ old('address_line_2', $profile?->address_line_2) }}">
<div class="row">
    <div><label for="town_city">{{ __('public.profile._account-fields.blade.town_or_city') }}</label><input id="town_city" name="town_city" value="{{ old('town_city', $profile?->town_city) }}" required></div>
    <div><label for="county">{{ __('public.profile._account-fields.blade.county') }}</label><input id="county" name="county" value="{{ old('county', $profile?->county) }}"></div>
    <div><label for="postcode">{{ __('public.profile._account-fields.blade.postcode') }}</label><input id="postcode" name="postcode" value="{{ old('postcode', $profile?->postcode) }}" required></div>
</div>
<div class="row">
    <div><label for="mobile_number">{{ __('public.profile._account-fields.blade.mobile_number') }}</label><input id="mobile_number" name="mobile_number" value="{{ old('mobile_number', $profile?->mobile_number) }}" placeholder="+447700900123" required></div>
    <div><label for="landline_number">{{ __('public.profile._account-fields.blade.landline_number') }}</label><input id="landline_number" name="landline_number" value="{{ old('landline_number', $profile?->landline_number) }}"></div>
    <div><label for="whatsapp_number">{{ __('public.profile._account-fields.blade.separate_whatsapp_number') }}</label><input id="whatsapp_number" name="whatsapp_number" value="{{ old('whatsapp_number', $profile?->whatsapp_number) }}"><small>{{ __('public.profile._account-fields.blade.leave_blank_to_use_your_mobile_number') }}</small></div>
</div>
<label for="telegram_username">{{ __('public.profile._account-fields.blade.telegram_username') }}</label>
<input id="telegram_username" name="telegram_username" value="{{ old('telegram_username', $profile?->telegram_username) }}">
<p>
    <a
        href="{{ \App\Support\PrivacyNotice::url() }}"
        @if (\App\Support\PrivacyNotice::opensExternally()) target="_blank" rel="noopener noreferrer" @endif
    >{{ __('public.profile._account-fields.blade.read_the_privacy_notice') }}</a>
</p>
<fieldset>
    <legend>{{ __('public.profile._account-fields.blade.your_myapes_services') }}</legend>
    @foreach (['apes-cic' => 'APES CIC', 'shelter-rescue' => 'APES Shelter and Rescue', 'pet-care-clinic' => 'APES Pet Care Clinic'] as $key => $label)
        <label class="inline-check"><input type="checkbox" name="services[]" value="{{ $key }}" @checked(in_array($key, old('services', $selectedServices), true))> {{ $label }}</label>
    @endforeach
</fieldset>
<fieldset>
    <legend>{{ __('public.profile._account-fields.blade.optional_contact_preferences') }}</legend>
    @foreach (['calls' => 'Calls', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'email' => 'Email'] as $channel => $label)
        <label class="inline-check"><input type="checkbox" name="contact_{{ $channel }}" value="1" @checked(old('contact_'.$channel, $preference?->{$channel} ?? false))> {{ $label }}</label>
    @endforeach
    <small data-email-preference-help>{{ __('public.profile._account-fields.blade.email_preference_help') }}</small>
    <input type="hidden" name="contact_preferences_confirmed" value="1">
</fieldset>
