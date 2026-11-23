@extends('layouts.app')

@section('title', __('public.maintenance.blade.maintenance_myapes_core'))

@push('head')
    <meta http-equiv="refresh" content="60">
    <meta name="robots" content="noindex, nofollow">
@endpush

@section('content')
    <div class="panel">
        <x-critter name="moss" pose="oops" class="brand-mascot" decorative />
        <p>{{ __('public.maintenance.blade.myapes_core') }}</p>
        <h1>{{ __('public.maintenance.blade.temporarily_unavailable') }}</h1>
        <p class="muted">{{ $message }}</p>
        @if ($plannedEndAt)
            <p class="muted">{{ __('public.errors.planned_end') }} <time datetime="{{ $plannedEndAt->toIso8601String() }}">{{ $plannedEndAt->format('Y-m-d H:i T') }}</time>.</p>
        @endif
        <p class="muted">{{ __('public.maintenance.blade.any_planned_time_is_informational_service_will_not_resu') }}</p>
        <p class="muted">
            {{ __('public.maintenance.blade.this_page_checks_again_every_60_seconds_staff_who_manag') }}
            <a href="{{ url('/staff/login') }}">{{ __('public.maintenance.blade.sign_in_for_recovery') }}</a>.
        </p>
    </div>
@endsection