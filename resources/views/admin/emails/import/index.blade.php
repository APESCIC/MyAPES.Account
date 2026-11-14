@extends('layouts.app')

@section('title', __('admin.emails.import.page_title'))

@section('content')
    <header class="page-heading">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.emails.index') }}">{{ __('admin.emails.heading') }}</a></p>
            <h1>{{ __('admin.emails.import.heading') }}</h1>
            <p>{{ __('admin.emails.import.intro', ['address' => $address]) }}</p>
            <p class="muted" data-reply-mailbox-state>
                @if(! $repliesEnabled)
                    {{ __('admin.emails.import.status_off') }}
                @elseif(! $pollingConfigured)
                    {{ __('admin.emails.import.status_no_mailbox') }}
                @else
                    {{ __('admin.emails.import.status_on') }}
                @endif
            </p>
        </div>
    </header>

    @include('admin.emails._tabs', ['activeTab' => 'import'])

    <div class="panel-stack" data-email-import>
        @include('admin.emails.import._monitor')
        @include('admin.emails.import._connection')
        @include('admin.emails.import._result', ['report' => $report])
        @include('admin.emails.import._status')
        @include('admin.emails.import._readiness')
        @include('admin.emails.import._log')
    </div>
@endsection
