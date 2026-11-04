@extends('layouts.app')

@section('title', __('callers.title').' | '.__('workspace.app_name'))

@section('content')
    <div class="panel" data-callers>
        <h1>{{ __('callers.title') }}</h1>
        <p class="muted">{{ __('callers.intro') }}</p>
        <form method="get" action="{{ route('callers.index') }}" class="actions" role="search">
            <label for="caller-search" class="visually-hidden">{{ __('callers.search_label') }}</label>
            <input id="caller-search" name="q" value="{{ $term }}" minlength="2" placeholder="{{ __('callers.search_placeholder') }}">
            <button type="submit">{{ __('callers.search') }}</button>
        </form>
    </div>

    @if($term !== '')
        <div class="panel">
            <h2>{{ __('callers.results') }}</h2>
            @forelse($callers as $caller)
                <p data-caller-result="{{ $caller->id }}">
                    <a href="{{ route('callers.show', $caller) }}">{{ $caller->name }}</a>
                    <span class="muted">· {{ $caller->username }}</span>
                </p>
            @empty
                <p class="muted">{{ __('callers.no_results') }}</p>
            @endforelse
        </div>
    @endif
@endsection
