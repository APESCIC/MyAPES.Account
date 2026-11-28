@extends('layouts.app')

@section('title', __('admin.security.keys_page_title'))

@section('content')
    <header class="page-heading">
        <div>
            <p class="eyebrow">{{ __('admin.security.eyebrow') }}</p>
            <h1>{{ __('admin.settings.keys_and_secrets.heading') }}</h1>
            <p>{{ __('admin.settings.keys_and_secrets.intro') }}</p>
        </div>
    </header>

    <div class="panel-stack">
        @include('admin.settings.tabs.keys-and-secrets', [
            'mailboxPassword' => $mailboxPassword,
            'serverOnly' => $serverOnly,
        ])
    </div>
@endsection
