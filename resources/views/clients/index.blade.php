@extends('layouts.app')

@section('title', __('clients.title').' | '.__('workspace.app_name'))

@section('content')
    <div class="panel">
        <h1>{{ __('clients.title') }}</h1>
        <p class="muted">{{ __('clients.intro') }}</p>

        <form method="get" action="{{ route('clients.index') }}">
            <label for="q">{{ __('clients.search') }}</label>
            <input id="q" name="q" value="{{ $q ?? '' }}" placeholder="{{ __('clients.search_placeholder') }}" autocomplete="off" autofocus>
            <button type="submit">{{ __('clients.search_submit') }}</button>
            @if($q ?? '')
                <a href="{{ route('clients.index') }}">{{ __('clients.clear') }}</a>
            @endif
        </form>
    </div>

    @if(($q ?? '') !== '')
        <div class="panel">
            <h2>{{ __('clients.search_results') }}</h2>
            @if($clients->isNotEmpty())
                @foreach($clients as $client)
                    <p>
                        <a href="{{ route('clients.show', $client) }}">{{ $client->name }}</a>
                        <span class="muted">· {{ $client->email ?: $client->username }}</span>
                    </p>
                @endforeach
            @else
                <p class="muted">{{ __('clients.no_results', ['query' => $q]) }}</p>
            @endif
        </div>
    @endif

    <div class="panel" id="create" data-client-create>
        <h2>{{ __('clients.create_heading') }}</h2>
        <p class="muted">{{ __('clients.create_intro') }}</p>
        <form method="post" action="{{ route('clients.store') }}" data-client-create-form>
            @csrf
            <label for="name">{{ __('clients.fields.name') }}</label>
            <input id="name" name="name" value="{{ old('name') }}" required autocomplete="name">

            <label for="email">{{ __('clients.fields.email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" data-client-email>
            <p class="muted">{{ __('clients.fields.email_hint') }}</p>

            <label for="mobile_number">{{ __('clients.fields.mobile') }}</label>
            <input id="mobile_number" name="mobile_number" value="{{ old('mobile_number') }}" required autocomplete="tel">

            <label for="landline_number">{{ __('clients.fields.landline') }}</label>
            <input id="landline_number" name="landline_number" value="{{ old('landline_number') }}" autocomplete="tel">

            <label for="address_line_1">{{ __('clients.fields.address_line_1') }}</label>
            <input id="address_line_1" name="address_line_1" value="{{ old('address_line_1') }}" required autocomplete="address-line1">

            <label for="address_line_2">{{ __('clients.fields.address_line_2') }}</label>
            <input id="address_line_2" name="address_line_2" value="{{ old('address_line_2') }}" autocomplete="address-line2">

            <label for="town_city">{{ __('clients.fields.town_city') }}</label>
            <input id="town_city" name="town_city" value="{{ old('town_city') }}" required autocomplete="address-level2">

            <label for="county">{{ __('clients.fields.county') }}</label>
            <input id="county" name="county" value="{{ old('county') }}" autocomplete="address-level1">

            <label for="postcode">{{ __('clients.fields.postcode') }}</label>
            <input id="postcode" name="postcode" value="{{ old('postcode') }}" required autocomplete="postal-code">

            <label for="pin">{{ __('clients.fields.pin') }}</label>
            <input id="pin" name="pin" type="password" inputmode="numeric" autocomplete="new-password" required minlength="{{ $pinMin }}" maxlength="{{ $pinMax }}">
            <p class="muted">{{ __('clients.fields.pin_hint', ['min' => $pinMin, 'max' => $pinMax]) }}</p>

            <label for="pin_confirmation">{{ __('clients.fields.pin_confirmation') }}</label>
            <input id="pin_confirmation" name="pin_confirmation" type="password" inputmode="numeric" autocomplete="new-password" required minlength="{{ $pinMin }}" maxlength="{{ $pinMax }}">

            <label class="inline-check" for="send_set_password_email">
                <input
                    id="send_set_password_email"
                    type="checkbox"
                    name="send_set_password_email"
                    value="1"
                    @checked((bool) old('send_set_password_email'))
                    data-client-send-email
                >
                {{ __('clients.fields.send_set_password_email') }}
            </label>
            <p class="muted">{{ __('clients.fields.send_set_password_email_hint') }}</p>

            <fieldset>
                <legend>{{ __('clients.fields.consent_legend') }}</legend>
                <label class="inline-check" for="staff_assisted_consent">
                    <input
                        id="staff_assisted_consent"
                        type="checkbox"
                        name="staff_assisted_consent"
                        value="1"
                        required
                        @checked((bool) old('staff_assisted_consent'))
                    >
                    {{ __('clients.fields.consent') }}
                </label>
            </fieldset>

            <button type="submit">{{ __('clients.create_submit') }}</button>
        </form>
    </div>
@endsection
