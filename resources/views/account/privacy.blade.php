@extends('layouts.app')

@section('title', __('account.settings.tabs.privacy').__('seo.default.title_suffix'))
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
            <p class="muted">{{ __('account.settings.privacy.overview') }}</p>
            <p>
                <a href="{{ route('privacy') }}">{{ __('account.settings.privacy_notice_link') }}</a>
                ·
                <a href="{{ route('cookies') }}">{{ __('account.settings.cookies_notice_link') }}</a>
            </p>
        </section>

        @include('account._privacy-contact-preferences')
        @include('account._privacy-email-opt-in')
        @include('account._privacy-notifications')
        @include('account._privacy-cookies')
        @include('account._privacy-data-export')
        @include('account._privacy-deletion')
    </div>
@endsection