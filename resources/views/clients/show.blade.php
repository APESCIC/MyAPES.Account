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
            <p><strong>{{ __('clients.account_state') }}:</strong> {{ __("clients.account_states.{$client->accountState()}") }}</p>
            @if(($missingMandatory ?? []) !== [])
                <p class="status" data-client-missing-details>
                    {{ __('clients.missing_details_prompt') }}
                    {{ collect($missingMandatory)->map(fn (string $key) => __("clients.fields.{$key}"))->implode(', ') }}.
                    {{ __('clients.missing_details_prompt_no_block') }}
                </p>
            @endif
            @unless($hubCanManageAccess ?? false)
                <p class="muted">{{ __('admin.users.hub.staff_read_only') }}</p>
            @endunless
            <p>
                <a href="{{ route('clients.index') }}">{{ __('clients.back') }}</a>
            </p>
            @include('admin.users.partials.hub-tabs', [
                'tabRoute' => 'clients.show',
                'tabParam' => 'client',
                'tabSubject' => $client,
            ])
        </div>

        @include('admin.users.partials.hub-temporary-password')

        @if($plainPin)
            <div class="panel" data-client-pin-once>
                <h2>{{ __('clients.pin_once_heading') }}</h2>
                <p class="muted">{{ __('clients.pin_once_intro') }}</p>
                <p data-client-pin-value><code>{{ $plainPin }}</code></p>
                <p class="muted">{{ __('clients.pin_once_warning') }}</p>
            </div>
        @endif

        @if($hubTab === 'overview')
            <div data-hub-overview>
                @include('callers.partials.verify')
                @include('callers.partials.details')
                @include('callers.partials.call-logs')
                @include('admin.users.partials.hub-actions')
                @include('admin.users.partials.hub-notes', [
                    'noteStoreRoute' => 'clients.notes.store',
                    'noteSubject' => $client,
                    'isAdminHub' => false,
                ])
            </div>
        @endif

        @if($hubTab === 'account_profile')
            @include('admin.users.partials.hub-account-profile-readonly')
            <p class="muted">{{ __('admin.users.hub.staff_read_only') }}</p>
        @endif

        @if($hubTab === 'account_management')
            @include('admin.users.partials.hub-create-account-soon')
            @if(! $hubIsProfileOnly)
                @include('admin.users.partials.hub-account-summary')
                @include('admin.users.partials.hub-account-actions')
                @unless($hubCanManageAccess)
                    <p class="muted">{{ __('admin.users.hub.staff_read_only') }}</p>
                @endunless
            @endif
        @endif

        @if($hubTab === 'records')
            @include('admin.users.partials.hub-records')
        @endif

        @if($hubTab === 'activity')
            @include('admin.users.partials.hub-activity')
        @endif
    </div>
@endsection
