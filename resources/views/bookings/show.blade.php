@extends('layouts.app')

@section('title', $booking['title'].' | '.__('workspace.app_name'))

@section('content')
    <article class="panel" data-bookings-show="{{ $current }}">
        <h1>{{ $booking['title'] }}</h1>
        <p class="muted">{{ $booking['intro'] }}</p>
        <nav aria-label="{{ __('bookings.nav_label') }}">
            <ul class="legal-nav">
                @foreach($pages as $page)
                    <li>
                        @if($page['slug'] === $current)
                            <a href="{{ route('bookings.show', $page['slug']) }}" aria-current="page">{{ $page['title'] }}</a>
                        @else
                            <a href="{{ route('bookings.show', $page['slug']) }}">{{ $page['title'] }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>

        <p>{{ __('bookings.fallback') }}</p>
        <div class="actions">
            <a class="is-active" href="{{ $booking['url'] }}" target="_blank" rel="noopener noreferrer" data-bookings-fallback>
                {{ __('bookings.schedule_online') }} <span class="visually-hidden">{{ __('bookings.new_tab') }}</span>
            </a>
        </div>

        <iframe
            class="bookings-frame"
            src="{{ $booking['url'] }}"
            title="{{ __('bookings.frame_title', ['service' => $booking['title']]) }}"
            loading="lazy"
            referrerpolicy="strict-origin-when-cross-origin"
            data-bookings-frame
        ></iframe>
    </article>
@endsection
