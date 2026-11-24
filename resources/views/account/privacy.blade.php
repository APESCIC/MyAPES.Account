@extends('layouts.app')

@section('title', __('account.settings.tabs.privacy').' | MyAPES Account')
@section('page_title', __('account.settings.tabs.privacy'))
@section('page_subtitle', __('account.settings.privacy_intro'))

@section('content')
    <div class="panel-stack">
        <section class="panel" aria-labelledby="account-privacy-title">
            <h1 id="account-privacy-title">{{ __('account.settings.tabs.privacy') }}</h1>
            <p class="muted">{{ __('account.settings.privacy_intro') }}</p>
            @include('account._subnav')
        </section>

        <section class="panel" id="privacy-overview">
            <p class="muted">{{ __('account.settings.privacy_body') }}</p>
            <p>
                <a href="{{ route('privacy') }}">{{ __('account.settings.privacy_notice_link') }}</a>
                ·
                <a href="{{ route('cookies') }}">{{ __('account.settings.cookies_notice_link') }}</a>
            </p>
        </section>
    </div>
@endsection
