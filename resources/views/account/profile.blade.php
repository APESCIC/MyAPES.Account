@extends('layouts.app')

@section('title', __('account.settings.tabs.profile').__('seo.title.title_suffix'))
@section('page_title', __('account.settings.tabs.profile'))
@section('page_subtitle', __('account.settings.profile_intro'))

@section('content')
    <div class="panel-stack">
        <section class="panel" aria-labelledby="account-profile-title">
            <h1 id="account-profile-title">{{ __('account.settings.tabs.profile') }}</h1>
            <p class="muted">{{ __('account.settings.profile_intro') }}</p>
            @include('account._subnav')
        </section>

        @if ($usesStaffDirectoryProfile ?? false)
            @include('account._profile-staff-workplace')
        @else
            @include('account._profile-personal')
        @endif
    </div>
@endsection