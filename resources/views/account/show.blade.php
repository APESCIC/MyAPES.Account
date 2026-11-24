@extends('layouts.app')

@section('title', __('account.settings.tabs.account').' | MyAPES Account')
@section('page_title', __('account.settings.tabs.account'))
@section('page_subtitle', __('account.settings.account_intro'))

@section('content')
    <div class="panel-stack">
        <section class="panel" aria-labelledby="account-settings-title">
            <h1 id="account-settings-title">{{ __('account.settings.tabs.account') }}</h1>
            <p class="muted">{{ __('account.settings.account_intro') }}</p>
            @include('account._subnav')
        </section>

        <section class="panel" id="account-overview">
            <p class="muted">{{ __('account.settings.account_body') }}</p>
        </section>
    </div>
@endsection
