@extends('layouts.app')

@section('title', 'Access denied | MyAPES Account')

@section('content')
    @php
        $denialMessage = trim($exception->getMessage());
        $useFriendlyDenial = $denialMessage === '' || $denialMessage === 'This action is unauthorized.';
    @endphp
    <div class="panel">
        <h1>{{ __('public.403.blade.access_denied') }}</h1>
        <p class="muted">
            {{ $useFriendlyDenial
                ? 'You do not have permission to view this page. If you need access, contact an APES administrator.'
                : $denialMessage }}
        </p>
        <div class="actions">
            <a href="{{ route('home') }}">{{ __('public.403.blade.back_to_home') }}</a>
            @auth
                <a href="{{ route('dashboard') }}">{{ __('public.403.blade.go_to_dashboard') }}</a>
            @endauth
        </div>
    </div>
@endsection
