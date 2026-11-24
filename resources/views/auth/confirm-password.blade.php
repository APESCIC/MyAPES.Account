@extends('layouts.app')

@section('title', __('auth.confirm_password.title'))

@section('content')
    @php
        $qaProfile = \App\Core\Qa\QaLogin::enabled()
            ? \App\Core\Qa\QaLogin::profileFor(auth()->user())
            : null;
    @endphp
    <div class="panel" data-passkeys-confirm @if($qaProfile) data-qa-confirm @endif>
        @if ($qaProfile !== null)
            <h1>{{ __('qa.confirm.heading', ['profile' => \App\Core\Qa\QaLogin::label($qaProfile)]) }}</h1>
            <p class="muted">{{ __('qa.confirm.intro') }}</p>
            <x-mascot-tip />
            <form method="post" action="{{ route('qa.login') }}">
                @csrf
                <input type="hidden" name="profile" value="{{ $qaProfile }}">
                <input type="hidden" name="return_to" value="{{ \App\Core\Qa\QaLogin::safeReturnPath(session('url.intended')) ?? '' }}">
                <div class="actions">
                    <button type="submit" data-qa-autoopen>{{ __('qa.confirm.submit') }}</button>
                    <a href="{{ route('account.show') }}">{{ __('auth.confirm_password.cancel') }}</a>
                </div>
            </form>
        @else
            <h1>{{ __('auth.confirm_password.heading') }}</h1>
            <p class="muted">{{ __('auth.confirm_password.intro') }}</p>
            <x-mascot-tip />
            <form method="post" action="{{ route('password.confirm.store') }}">
                @csrf
                <label for="password">{{ __('auth.common.password') }}</label>
                <input id="password" type="password" name="password" autocomplete="current-password" required autofocus>

                <div class="actions">
                    <button type="submit">{{ __('auth.confirm_password.submit') }}</button>
                    <a href="{{ route('account.show') }}">{{ __('auth.confirm_password.cancel') }}</a>
                </div>
            </form>

            @if(auth()->user()?->isLocalPasswordIdentity() && auth()->user()?->hasPasskeysEnabled())
                <div class="passkey-confirm mt-2">
                    <p class="muted">{{ __('auth.passkeys.confirm_intro') }}</p>
                    <p class="error" role="alert" hidden data-passkey-error></p>
                    <div class="actions">
                        <button type="button" data-passkey-confirm>{{ __('auth.passkeys.confirm_button') }}</button>
                    </div>
                </div>
            @endif
        @endif
    </div>
@endsection
