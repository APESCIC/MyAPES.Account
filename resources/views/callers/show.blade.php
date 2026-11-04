@extends('layouts.app')

@section('title', $caller->name.' | '.__('callers.title'))

@section('content')
    <div class="panel" data-caller="{{ $caller->id }}">
        <p class="eyebrow">{{ __('callers.title') }}</p>
        <h1>{{ $caller->name }}</h1>
        <p class="muted">{{ $caller->username }}</p>
        <a href="{{ route('callers.index') }}">{{ __('callers.back') }}</a>
    </div>

    <div class="panel" id="verify" data-caller-verification>
        <h2>{{ __('callers.verify_heading') }}</h2>
        @if($verified)
            <x-alert tone="ok">{{ __('callers.unlocked_until', ['time' => \Illuminate\Support\Carbon::createFromTimestamp($unlockedUntil, config('app.timezone'))->format('H:i')]) }}</x-alert>
            <form method="post" action="{{ route('callers.end-call', $caller) }}" data-end-call>
                @csrf
                <div class="actions">
                    <button type="submit">{{ __('callers.end_call') }}</button>
                </div>
            </form>
        @elseif($lockedForSeconds > 0)
            <x-alert tone="warning">{{ __('callers.locked', ['minutes' => (int) ceil($lockedForSeconds / 60)]) }}</x-alert>
        @elseif($positions === null)
            <x-alert tone="warning">{{ __('callers.no_pin') }}</x-alert>
        @else
            <p>{{ __('callers.ask_for_digits', ['first' => $positions[0], 'second' => $positions[1]]) }}</p>
            <form method="post" action="{{ route('callers.verify', $caller) }}" autocomplete="off">
                @csrf
                <div class="row">
                    @foreach($positions as $position)
                        <div>
                            <label for="pin-digit-{{ $position }}">{{ __('callers.digit_label', ['position' => $position]) }}</label>
                            <input id="pin-digit-{{ $position }}" type="password" name="pin_digits[{{ $position }}]" inputmode="numeric" pattern="[0-9]" maxlength="1" autocomplete="off" required>
                        </div>
                    @endforeach
                </div>
                @error('pin_digits')
                    <p class="sg-field__error">{{ $message }}</p>
                @enderror
                <div class="actions">
                    <button type="submit">{{ __('callers.check') }}</button>
                </div>
            </form>
            <p class="muted">{{ __('callers.digits_hint') }}</p>
        @endif
    </div>

    <div class="panel" id="details" data-caller-details data-locked="{{ $verified ? 'false' : 'true' }}">
        <h2>{{ __('callers.details_heading') }}</h2>
        @unless($verified)
            <p class="muted">{{ __('callers.details_locked') }}</p>
        @endunless
        <form method="post" action="{{ route('callers.details.update', $caller) }}">
            @csrf
            @method('put')
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
@endsection
