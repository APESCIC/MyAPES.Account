@extends('layouts.app')

@section('title', __('seo.home.title'))
@section('meta_description', __('seo.home.description'))
@section('meta_keywords', __('seo.home.keywords'))

@section('content')
    <div class="panel">
        <h1 class="welcome-heading">
            {{ __('auth.landing.heading') }}
            <img src="{{ asset('mascot/spike-welcome.png') }}" alt="" class="welcome-heading__mascot" width="1024" height="1024">
        </h1>
        <p class="muted">{{ __('auth.common.support_tools_intro') }}</p>
        <x-mascot-tip />
        <div class="grid auth-service-tiles">
            <div class="panel panel-flat">
                <span class="service-label apes-cic">{{ __('auth.common.apes_cic') }}</span>
                <p>{{ __('auth.common.apes_cic_blurb') }}</p>
            </div>
            <div class="panel panel-flat">
                <span class="service-label apes-shelter">{{ __('auth.common.apes_shelter') }}</span>
                <p>{{ __('auth.common.apes_shelter_blurb') }}</p>
            </div>
            <div class="panel panel-flat">
                <span class="service-label apes-petcare">{{ __('auth.common.apes_petcare') }}</span>
                <p>{{ __('auth.common.apes_petcare_blurb') }}</p>
            </div>
        </div>
    </div>
    <div class="grid auth-entry-grid">
        <div class="panel">
            <h2>{{ __('auth.landing.public_access') }}</h2>
            <p class="muted">{{ __('auth.landing.public_access_blurb') }}</p>
            <div class="actions">
                <a href="{{ route('public.login') }}">{{ __('auth.common.public_login') }}</a>
                <a href="{{ route('public.register') }}">{{ __('auth.common.register') }}</a>
            </div>
        </div>
        <div class="panel">
            <h2>{{ __('auth.landing.staff_access') }}</h2>
            <p class="muted">{{ __('auth.landing.staff_access_blurb') }}</p>
            <div class="actions">
                <a href="{{ route('staff.login') }}">{{ __('auth.common.staff_login') }}</a>
            </div>
        </div>
        @foreach($publicPluginNavigation ?? [] as $publicPluginNav)
            <div class="panel">
                <h2>{{ $publicPluginNav->label === 'Recruitment' ? __('auth.landing.open_roles') : $publicPluginNav->label }}</h2>
                <p class="muted">{{ __('auth.landing.open_roles_blurb') }}</p>
                <div class="actions">
                    <a href="{{ route($publicPluginNav->routeName) }}">{{ __('auth.landing.view_open_roles') }}</a>
                </div>
            </div>
        @endforeach
    </div>
@endsection
