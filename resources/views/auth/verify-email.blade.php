@extends('layouts.app')

@section('title', __('auth.verify_email.title'))

@section('content')
    <div class="panel">
        <h1>{{ __('auth.verify_email.heading') }}</h1>
        <p class="muted">{{ __('auth.verify_email.intro') }}</p>
        @if (auth()->user()?->email)
            <p>{{ __('auth.verify_email.sent_to', ['email' => auth()->user()->email]) }}</p>
        @endif
        <x-mascot-tip />
        <form method="post" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit">{{ __('auth.verify_email.resend') }}</button>
        </form>
        <p class="muted">{{ __('auth.verify_email.resend_note') }}</p>
        <form method="post" action="{{ route('auth.logout') }}">
            @csrf
            <button type="submit" class="button-secondary">{{ __('auth.common.log_out') }}</button>
        </form>
    </div>
@endsection
