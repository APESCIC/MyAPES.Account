@extends('layouts.app')

@section('title', ($heading ?? __('auth.reset_password.heading')).' | MyAPES Account')

@section('content')
    <div class="panel">
        <h1>{{ $heading ?? __('auth.reset_password.heading') }}</h1>
        <p class="muted">{{ __('auth.reset_password.intro') }}</p>
        <x-mascot-tip />
        <form method="post" action="{{ $formAction ?? route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <label for="email">{{ __('auth.common.email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="username" required>

            <label for="password">{{ __('auth.reset_password.new_password') }}</label>
            <input id="password" type="password" name="password" autocomplete="new-password" required>

            <label for="password_confirmation">{{ __('auth.reset_password.confirm_new_password') }}</label>
            <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>

            <div class="actions">
                <button type="submit">{{ __('auth.reset_password.submit') }}</button>
                <a href="{{ route('public.login') }}">{{ __('auth.common.back_to_public_login') }}</a>
            </div>
        </form>
    </div>
@endsection
