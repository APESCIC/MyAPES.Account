@extends('layouts.app')

@section('title', $client->name.' | '.__('clients.title').' | '.__('workspace.app_name'))

@section('content')
    <div class="panel-stack" data-client-show data-profile-hub>
        <div class="panel">
            <h1>{{ $client->name }}</h1>
            <p class="muted">{{ __('clients.show_intro') }}</p>
            <p><strong>{{ __('clients.fields.username') }}:</strong> {{ $client->username }}</p>
            <p><strong>{{ __('clients.fields.email') }}:</strong> {{ $client->email ?: __('clients.no_email') }}</p>
            <p><strong>{{ __('clients.fields.mobile') }}:</strong> {{ $client->profile?->mobile_number }}</p>
            <p class="muted">{{ __('admin.users.hub.staff_read_only') }}</p>
            <p>
                <a href="{{ route('callers.show', $client) }}">{{ __('clients.open_in_callers') }}</a>
                ·
                <a href="{{ route('clients.index') }}">{{ __('clients.back') }}</a>
            </p>
            @include('admin.users.partials.hub-tabs', [
                'tabRoute' => 'clients.show',
                'tabParam' => 'client',
                'tabSubject' => $client,
            ])
        </div>

        @if($plainPin)
            <div class="panel" data-client-pin-once>
                <h2>{{ __('clients.pin_once_heading') }}</h2>
                <p class="muted">{{ __('clients.pin_once_intro') }}</p>
                <p data-client-pin-value><code>{{ $plainPin }}</code></p>
                <p class="muted">{{ __('clients.pin_once_warning') }}</p>
            </div>
        @endif

        @if($hubTab === 'overview')
            @include('admin.users.partials.hub-actions')
            @include('admin.users.partials.hub-notes', [
                'noteStoreRoute' => 'clients.notes.store',
                'noteSubject' => $client,
                'isAdminHub' => false,
            ])
        @endif

        @if($hubTab === 'details')
            <section class="panel" aria-labelledby="client-details-title">
                <h2 id="client-details-title">{{ __('admin.users.public_profile') }}</h2>
                <dl class="admin-definition-list">
                    <div><dt>{{ __('admin.users.preferred_name') }}</dt><dd>{{ $profile?->preferred_name ?: '—' }}</dd></div>
                    <div><dt>{{ __('admin.users.organisation') }}</dt><dd>{{ $profile?->organization ?: '—' }}</dd></div>
                    <div><dt>{{ __('admin.users.phone') }}</dt><dd>{{ $profile?->phone ?: '—' }}</dd></div>
                </dl>
                <p class="muted">{{ __('admin.users.hub.staff_read_only') }}</p>
            </section>
        @endif

        @if($hubTab === 'access')
            <section class="panel">
                <h2>{{ __('admin.users.effective_access') }}</h2>
                <p class="muted">{{ __('admin.users.hub.staff_read_only') }}</p>
            </section>
        @endif

        @if($hubTab === 'records')
            @include('admin.users.partials.hub-records')
        @endif

        @if($hubTab === 'activity')
            @include('admin.users.partials.hub-activity')
        @endif
    </div>
@endsection
