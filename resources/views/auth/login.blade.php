@extends('layouts.app')

@section('title', __('auth.staff_sign_in.title'))

@section('content')
    <div class="panel">
        <img src="{{ asset('mascot/spike-welcome.png') }}" alt="{{ __('auth.staff_sign_in.mascot_alt') }}" class="hero-image" width="1024" height="1024">
        <h1>{{ __('auth.staff_sign_in.heading') }}</h1>
        <p class="muted">{{ __('auth.common.support_tools_intro') }}</p>
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
        <form method="get" action="{{ route('staff.auth.login') }}">
            <button type="submit">{{ __('auth.common.continue_cloudron') }}</button>
        </form>
    </div>
@endsection
