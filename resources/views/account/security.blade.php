@extends('layouts.app')

@section('title', __('account.settings.tabs.security').' | MyAPES Account')
@section('page_title', __('account.settings.tabs.security'))
@section('page_subtitle', __('account.settings.security_intro'))

@section('content')
    <div class="panel-stack">
        <section class="panel" aria-labelledby="account-security-title">
            <h1 id="account-security-title">{{ __('account.settings.tabs.security') }}</h1>
            <p class="muted">{{ __('account.settings.security_intro') }}</p>
            @include('account._subnav')
        </section>

        <section class="panel" id="passkeys">
            <p class="muted">{{ __('account.settings.security_body') }}</p>
        </section>
    </div>
@endsection
