@extends('layouts.app')

@section('title', __('account.settings.tabs.account').__('seo.default.title_suffix'))
@section('page_title', __('account.settings.tabs.account'))
@section('page_subtitle', __('account.settings.account_intro'))

@section('content')
    <div class="panel-stack">
        <section class="panel" aria-labelledby="account-settings-title">
            <h1 id="account-settings-title">{{ __('account.settings.tabs.account') }}</h1>
            <p class="muted">{{ __('account.settings.account_intro') }}</p>
            @include('account._subnav')
        </section>

        @include('account._sign-in-methods')
        @if ($usesStaffDirectoryProfile ?? false)
            @include('account._account-staff-directory')
        @else
            @include('account._email')
            @include('account._username')
        @endif
        @include('profile._appearance')
    </div>
@endsection