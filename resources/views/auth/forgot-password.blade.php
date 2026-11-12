@extends('layouts.app')

@section('title', __('auth.forgot_password.title'))

@section('content')
    <div class="panel">
        <h1>{{ __('auth.forgot_password.heading') }}</h1>
        <p class="muted">{{ __('auth.forgot_password.intro') }}</p>
        <x-mascot-tip />
        <form method="post" action="{{ route('password.email') }}">
            @csrf
            <x-bot-protection form="forgot_password" />
            <label for="email">{{ __('auth.common.email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required>

            <div class="actions">
                <button type="submit">{{ __('auth.forgot_password.submit') }}</button>
                <a href="{{ route('public.login') }}">{{ __('auth.common.back_to_public_login') }}</a>
            </div>
        </form>
    </div>
@endsection
