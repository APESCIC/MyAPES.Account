@extends('layouts.app')

@section('title', __('account.settings.tabs.security').__('seo.default.title_suffix'))
@section('page_title', __('account.settings.tabs.security'))
@section('page_subtitle', __('account.settings.security_intro'))

@section('content')
    <div class="panel-stack">
        <section class="panel" aria-labelledby="account-security-title">
            <h1 id="account-security-title">{{ __('account.settings.tabs.security') }}</h1>
            <p class="muted">{{ __('account.settings.security_intro') }}</p>
            @include('account._subnav')
        </section>

        @include('account._security-password')
        @include('account._security-passkeys')
        @include('account._security-two-factor')
        @include('account._security-phone-pin')
        @include('account._security-devices')
        @include('account._security-activity')
    </div>
@endsection