@extends('layouts.app')

@section('title', __('admin.security.audit.page_title'))

@section('content')
    <header class="page-heading">
        <div>
            <p class="eyebrow">{{ __('admin.security.eyebrow') }}</p>
            <h1>{{ __('admin.security.audit.heading') }}</h1>
            <p>{{ __('admin.security.audit.intro') }}</p>
        </div>
        <div class="actions">
            <a class="button" href="{{ route('admin.security.audit-log.export', request()->query()) }}">{{ __('admin.security.audit.export') }}</a>
        </div>
    </header>

    <form method="get" action="{{ route('admin.security.audit-log') }}" class="stacked-form panel" data-security-audit-filters>
        <label for="audit-actor">{{ __('admin.security.audit.filters.actor') }}</label>
        <input id="audit-actor" name="actor" type="search" value="{{ $filters['actor'] }}">

        <label for="audit-subject">{{ __('admin.security.audit.filters.subject') }}</label>
        <input id="audit-subject" name="subject" type="search" value="{{ $filters['subject'] }}">

        <label for="audit-event">{{ __('admin.security.audit.filters.event') }}</label>
        <select id="audit-event" name="event">
            <option value="">{{ __('admin.security.audit.filters.any_event') }}</option>
            @foreach($eventTypes as $type)
                <option value="{{ $type }}" @selected($filters['event'] === $type)>{{ $type }}</option>
            @endforeach
        </select>

        <label for="audit-from">{{ __('admin.security.audit.filters.from') }}</label>
        <input id="audit-from" name="from" type="date" value="{{ $filters['from'] }}">

        <label for="audit-to">{{ __('admin.security.audit.filters.to') }}</label>
        <input id="audit-to" name="to" type="date" value="{{ $filters['to'] }}">

        <div class="actions">
            <button type="submit">{{ __('admin.security.audit.filters.apply') }}</button>
        </div>
    </form>

    <section class="panel" aria-labelledby="audit-results-heading">
        <h2 id="audit-results-heading">{{ __('admin.security.audit.results') }}</h2>
        @if($entries->isEmpty())
            <p class="muted">{{ __('admin.security.audit.empty') }}</p>
        @else
            <table class="data-table" data-security-audit-table>
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.security.overview.columns.at') }}</th>
                        <th scope="col">{{ __('admin.security.overview.columns.event') }}</th>
                        <th scope="col">{{ __('admin.security.overview.columns.actor') }}</th>
                        <th scope="col">{{ __('admin.security.overview.columns.subject') }}</th>
                        <th scope="col">{{ __('admin.security.audit.columns.context') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($entries as $entry)
                        <tr>
                            <td>{{ $entry['at'] }}</td>
                            <td><code>{{ $entry['event'] }}</code></td>
                            <td>{{ $entry['actor'] ?? '—' }}</td>
                            <td>{{ $entry['subject'] ?? '—' }}</td>
                            <td><code>{{ $entry['context'] === [] ? '—' : json_encode($entry['context'], JSON_UNESCAPED_UNICODE) }}</code></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            {{ $entries->links() }}
        @endif
    </section>
@endsection
