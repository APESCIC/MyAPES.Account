@extends('layouts.app')

@section('title', __('admin.security.page_title'))

@section('content')
    <header class="page-heading">
        <div>
            <p class="eyebrow">{{ __('admin.security.eyebrow') }}</p>
            <h1>{{ __('admin.security.heading') }}</h1>
            <p>{{ __('admin.security.intro') }}</p>
        </div>
    </header>

    <section class="panel" aria-labelledby="security-overview-stats" data-security-overview>
        <h2 id="security-overview-stats">{{ __('admin.security.overview.stats_heading') }}</h2>
        <dl class="stat-grid">
            <div>
                <dt>{{ __('admin.security.overview.two_factor') }}</dt>
                <dd data-stat="two_factor_enabled">{{ $overview['two_factor_enabled'] }}</dd>
            </div>
            <div>
                <dt>{{ __('admin.security.overview.passkeys') }}</dt>
                <dd data-stat="passkey_enabled">{{ $overview['passkey_enabled'] }}</dd>
            </div>
            <div>
                <dt>{{ __('admin.security.overview.suspended') }}</dt>
                <dd data-stat="suspended">{{ $overview['suspended'] }}</dd>
            </div>
            <div>
                <dt>{{ __('admin.security.overview.failed_sign_ins') }}</dt>
                <dd data-stat="failed_sign_ins">{{ $overview['failed_sign_ins'] }}</dd>
            </div>
        </dl>
        <p class="muted">{{ __('admin.security.overview.failed_sign_ins_help', ['days' => \App\Services\SecurityOverviewAggregator::FAILED_SIGN_IN_DAYS]) }}</p>
    </section>

    <section class="panel" aria-labelledby="security-recent-events">
        <h2 id="security-recent-events">{{ __('admin.security.overview.recent_heading') }}</h2>
        @if($overview['recent_events'] === [])
            <p class="muted">{{ __('admin.security.overview.recent_empty') }}</p>
        @else
            <table class="data-table" data-security-recent-events>
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.security.overview.columns.at') }}</th>
                        <th scope="col">{{ __('admin.security.overview.columns.event') }}</th>
                        <th scope="col">{{ __('admin.security.overview.columns.actor') }}</th>
                        <th scope="col">{{ __('admin.security.overview.columns.subject') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($overview['recent_events'] as $event)
                        <tr>
                            <td>{{ $event['at'] }}</td>
                            <td><code>{{ $event['event'] }}</code></td>
                            <td>{{ $event['actor'] ?? '—' }}</td>
                            <td>{{ $event['subject'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        <p><a href="{{ route('admin.security.audit-log') }}">{{ __('admin.security.overview.open_audit_log') }}</a></p>
    </section>

    <ul class="panel-stack" data-security-hub>
        @foreach($links as $link)
            <li class="panel">
                <h2><a href="{{ $link['url'] }}">{{ $link['label'] }}</a></h2>
                <p>{{ $link['description'] }}</p>
            </li>
        @endforeach
    </ul>
@endsection
