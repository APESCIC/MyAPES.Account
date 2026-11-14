@extends('layouts.app')

@section('title', __('seo.staff_login.title'))
@section('meta_description', __('seo.staff_login.description'))
@section('meta_keywords', __('seo.staff_login.keywords'))

@section('content')
    <div class="panel">
        <h1>{{ __('auth.staff_login.heading') }}</h1>
        <p class="muted">{{ __('auth.staff_login.intro') }}</p>
        <x-mascot-tip />
        @if(app()->environment(['local', 'testing']))
            <p class="muted">{{ __('auth.staff_login.local_demo_note') }}</p>
            <form method="post" action="{{ route('staff.local-login.submit') }}">
                @csrf
                <label for="email">{{ __('auth.common.email') }}</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required>

                <label for="password">{{ __('auth.common.password') }}</label>
                <input id="password" type="password" name="password" autocomplete="current-password" required>

                <div class="actions">
                    <button type="submit">{{ __('auth.staff_login.local_submit') }}</button>
                </div>
            </form>
            <hr class="section-divider">
        @endif
        <form method="get" action="{{ route('staff.auth.login') }}">
            <button type="submit">{{ __('auth.common.continue_cloudron') }}</button>
        </form>
        @if (\App\Core\Qa\QaLogin::enabled())
            <x-qa.signin-panel :profiles="\App\Core\Qa\QaLogin::picker()" class="mt-2" />
        @endif
    </div>
@endsection
