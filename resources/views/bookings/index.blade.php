@extends('layouts.app')

@section('title', __('bookings.title').' | '.__('workspace.app_name'))

@section('content')
    <div class="panel" data-bookings-index>
        <h1>{{ __('bookings.title') }}</h1>
        <p class="muted">{{ __('bookings.intro') }}</p>
        <x-mascot-tip />
    </div>

    @foreach($pages as $page)
        <section class="panel" aria-labelledby="bookings-{{ $page['slug'] }}" data-bookings-page="{{ $page['slug'] }}">
            <h2 id="bookings-{{ $page['slug'] }}">{{ $page['title'] }}</h2>
            <p class="muted">{{ $page['intro'] }}</p>
            <div class="actions">
                <a class="is-active" href="{{ route('bookings.show', $page['slug']) }}">{{ __('bookings.open', ['service' => $page['title']]) }}</a>
            </div>
        </section>
    @endforeach
@endsection
