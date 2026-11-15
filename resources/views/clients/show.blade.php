@extends('layouts.app')

@section('title', $client->name.' | '.__('clients.title').' | '.__('workspace.app_name'))

@section('content')
    <div class="panel" data-client-show>
        <h1>{{ $client->name }}</h1>
        <p class="muted">{{ __('clients.show_intro') }}</p>
        <p><strong>{{ __('clients.fields.username') }}:</strong> {{ $client->username }}</p>
        <p><strong>{{ __('clients.fields.email') }}:</strong> {{ $client->email ?: __('clients.no_email') }}</p>
        <p><strong>{{ __('clients.fields.mobile') }}:</strong> {{ $client->profile?->mobile_number }}</p>
        <p>
            <a href="{{ route('callers.show', $client) }}">{{ __('clients.open_in_callers') }}</a>
            ·
            <a href="{{ route('clients.index') }}">{{ __('clients.back') }}</a>
        </p>
    </div>

    @if($plainPin)
        <div class="panel" data-client-pin-once>
            <h2>{{ __('clients.pin_once_heading') }}</h2>
            <p class="muted">{{ __('clients.pin_once_intro') }}</p>
            <p data-client-pin-value><code>{{ $plainPin }}</code></p>
            <p class="muted">{{ __('clients.pin_once_warning') }}</p>
        </div>
    @endif
@endsection
