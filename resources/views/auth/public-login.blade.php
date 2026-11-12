@extends('layouts.app')

@section('title', __('seo.public_login.title'))
@section('meta_description', __('seo.public_login.description'))
@section('meta_keywords', __('seo.public_login.keywords'))

@section('content')
    <div class="panel" data-passkeys-login>
        <h1>{{ __('auth.public_login.heading') }}</h1>
        <p class="muted">{{ __('auth.public_login.intro') }}</p>
        <x-mascot-tip />
        <form method="post" action="{{ route('public.login.submit') }}">
            @csrf
            <x-bot-protection form="login" />
            <label for="login">{{ __('auth.public_login.login_label') }}</label>
            <input id="login" type="text" name="login" value="{{ old('login') }}" autocomplete="username webauthn" required>

            <label for="password">{{ __('auth.common.password') }}</label>
            <input id="password" type="password" name="password" autocomplete="current-password" required>

            <label class="inline-check">
                <input type="checkbox" name="remember" value="1" id="remember" data-passkey-remember> {{ __('auth.common.remember_me') }}
            </label>

            <div class="actions">
                <button type="submit">{{ __('auth.common.login') }}</button>
                <button type="button" data-passkey-verify>{{ __('auth.passkeys.login_button') }}</button>
                <a href="{{ route('password.request') }}">{{ __('auth.common.forgot_password_link') }}</a>
                <a href="{{ route('public.register') }}">{{ __('auth.common.create_account') }}</a>
                <a href="{{ route('staff.login') }}">{{ __('auth.common.staff_login') }}</a>
            </div>
            <p class="error" role="alert" hidden data-passkey-error></p>
            <p class="muted">{{ __('auth.passkeys.login_fallback') }}</p>
        </form>
    </div>
@endsection
