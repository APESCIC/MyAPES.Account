@extends('layouts.app')

@section('title', __('account.settings.tabs.profile').' | MyAPES Account')
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
            <section class="panel" id="profile-directory-staff">
                <p class="muted">{{ __('account.settings.profile_staff_directory') }}</p>
            </section>
        @else
            @include('account._profile-personal')
        @endif
    </div>
@endsection