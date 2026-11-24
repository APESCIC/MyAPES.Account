@php
    $callerHubReturn = $callerHubReturn ?? false;
    $details = $details ?? $caller->profile;
@endphp
<div class="panel" id="details" data-caller-details data-locked="{{ $verified ? 'false' : 'true' }}">
    <h2>{{ __('callers.details_heading') }}</h2>
    @unless($verified)
        <p class="muted">{{ __('callers.details_locked') }}</p>
    @endunless
    <form method="post" action="{{ route('callers.details.update', $caller) }}">
        @csrf
        @method('put')
        @if($callerHubReturn)
            <input type="hidden" name="hub" value="1">
        @endif
        <fieldset @disabled(! $verified)>
            <legend class="visually-hidden">{{ __('callers.details_heading') }}</legend>
            <label for="caller-mobile">{{ __('callers.fields.mobile_number') }}</label>
            <input id="caller-mobile" name="mobile_number" value="{{ old('mobile_number', $details?->mobile_number) }}" required>
            <label for="caller-landline">{{ __('callers.fields.landline_number') }}</label>
            <input id="caller-landline" name="landline_number" value="{{ old('landline_number', $details?->landline_number) }}">
            <label for="caller-address-1">{{ __('callers.fields.address_line_1') }}</label>
            <input id="caller-address-1" name="address_line_1" value="{{ old('address_line_1', $details?->address_line_1) }}" required>
            <label for="caller-address-2">{{ __('callers.fields.address_line_2') }}</label>
            <input id="caller-address-2" name="address_line_2" value="{{ old('address_line_2', $details?->address_line_2) }}">
            <label for="caller-town">{{ __('callers.fields.town_city') }}</label>
            <input id="caller-town" name="town_city" value="{{ old('town_city', $details?->town_city) }}" required>
            <label for="caller-county">{{ __('callers.fields.county') }}</label>
            <input id="caller-county" name="county" value="{{ old('county', $details?->county) }}">
            <label for="caller-postcode">{{ __('callers.fields.postcode') }}</label>
            <input id="caller-postcode" name="postcode" value="{{ old('postcode', $details?->postcode) }}" required>
            <div class="actions">
                <button type="submit">{{ __('callers.save_details') }}</button>
            </div>
        </fieldset>
    </form>
</div>