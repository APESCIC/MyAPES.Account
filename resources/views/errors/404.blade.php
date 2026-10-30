@extends('layouts.app')

@section('title', 'Page not found | MyAPES Account')

@section('content')
    <div class="panel">
        <h1>{{ __('public.404.blade.page_not_found') }}</h1>
        <p class="muted">{{ __('public.404.blade.that_address_is_not_available_in_myapes_core_check_the_') }}</p>
        <div class="actions">
            <a href="{{ route('home') }}">{{ __('public.403.blade.back_to_home') }}</a>
            @auth
                <a href="{{ route('dashboard') }}">{{ __('public.403.blade.go_to_dashboard') }}</a>
            @endauth
        </div>
    </div>
@endsection
