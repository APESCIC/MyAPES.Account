@extends('layouts.app')

@section('title', __('auth.two_factor.setup_title'))

@section('content')
    <div class="panel" id="two-factor-setup">
        <h1>{{ __('auth.two_factor.setup_heading') }}</h1>
        <p class="muted">{{ __('auth.two_factor.setup_intro') }}</p>
        <x-mascot-tip />

        <div class="two-factor-qr" aria-hidden="false">
            {!! $qrSvg !!}
        </div>

        <p class="muted">{{ __('auth.two_factor.manual_entry') }}</p>
        <p><code class="otpauth-url">{{ $otpauthUrl }}</code></p>

        @if(count($recoveryCodes) > 0)
            <div id="recovery-codes-once">
                <h2>{{ __('auth.two_factor.recovery_codes_heading') }}</h2>
                <p class="muted">{{ __('auth.two_factor.recovery_codes_once') }}</p>
                <ul class="recovery-codes">
                    @foreach($recoveryCodes as $recoveryCode)
                        <li><code>{{ $recoveryCode }}</code></li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ route('two-factor.confirm') }}">
            @csrf
            <label for="code">{{ __('auth.two_factor.code_label') }}</label>
            <input
                id="code"
                type="text"
                name="code"
                inputmode="numeric"
                autocomplete="one-time-code"
                required
                autofocus
            >

            <div class="actions">
                <button type="submit">{{ __('auth.two_factor.confirm') }}</button>
                <a href="{{ route('account.security') }}">{{ __('auth.two_factor.back_to_profile') }}</a>
            </div>
        </form>
    </div>
@endsection
