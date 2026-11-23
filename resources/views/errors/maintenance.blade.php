<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="60">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('public.maintenance.blade.maintenance_myapes_core') }}</title>
    <style>
        :root { color-scheme: light dark; font-family: "Segoe UI", sans-serif; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; background: #062f35; color: #f4fbfa; }
        main { width: min(42rem, calc(100% - 2rem)); padding: clamp(1.5rem, 5vw, 3rem); border: 1px solid #3b7379; border-radius: 1rem; background: #0b4148; box-shadow: 0 1rem 3rem #00191d99; }
        h1 { margin-top: 0; font-family: Georgia, serif; font-size: clamp(2rem, 7vw, 3.25rem); }
        .message { padding: 1rem; border-left: .3rem solid #52f0f1; background: #062f35; white-space: pre-wrap; }
        a { color: #8ef7f8; }
    </style>
</head>
<body>
<main>
    @php($maintenanceCritter = \App\Support\Theme\CritterCast::resolve('moss', 'oops'))
    <p><img src="{{ asset($maintenanceCritter['svg']) }}" alt="" width="128" height="128" decoding="async" style="display:block;margin:0 auto 1rem;"></p>
    <p>{{ __('public.maintenance.blade.myapes_core') }}</p>
    <h1>{{ __('public.maintenance.blade.temporarily_unavailable') }}</h1>
    <p class="message">{{ $message }}</p>
    @if($plannedEndAt)
        <p>{{ __('public.errors.planned_end') }} <time datetime="{{ $plannedEndAt->toIso8601String() }}">{{ $plannedEndAt->format('Y-m-d H:i T') }}</time>.</p>
    @endif
    <p>{{ __('public.maintenance.blade.any_planned_time_is_informational_service_will_not_resu') }}</p>
    <p>This page checks again every 60 seconds. Staff who manage maintenance can <a href="{{ url('/staff/login') }}">{{ __('public.maintenance.blade.sign_in_for_recovery') }}</a>.</p>
</main>
</body>
</html>
