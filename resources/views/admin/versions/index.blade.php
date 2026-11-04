@extends('layouts.app')

@section('title', __('admin.versions.title').' | '.__('workspace.app_name'))

@section('content')
    @inject('ukDateTime', \App\Support\UkDateTime::class)
    <header class="page-heading">
        <div>
            <h1>{{ __('admin.versions.title') }}</h1>
            <p>{{ __('admin.versions.intro') }}</p>
        </div>
    </header>

    <section class="panel" data-admin-versions>
        <div class="module-settings-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.versions.name') }}</th>
                        <th scope="col">{{ __('admin.versions.type') }}</th>
                        <th scope="col">{{ __('admin.versions.installed') }}</th>
                        <th scope="col">{{ __('admin.versions.code') }}</th>
                        <th scope="col">{{ __('admin.versions.status') }}</th>
                        <th scope="col">{{ __('admin.versions.history') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr data-version-row="{{ $row['id'] }}" data-version-state="{{ $row['state'] }}">
                            <th scope="row">{{ $row['name'] }}</th>
                            <td>{{ $row['kind'] === 'module' ? __('admin.versions.kind_module') : __('admin.versions.kind_plugin') }}</td>
                            <td>{{ $row['installed_version'] ?? '—' }}</td>
                            <td>{{ $row['code_version'] }}</td>
                            <td>
                                @if($row['state'] === 'updated')
                                    <x-badge tone="ok">{{ __('admin.versions.state_updated', ['date' => $ukDateTime->formatDate($row['updated_at'])]) }}</x-badge>
                                @elseif($row['state'] === 'current')
                                    <x-badge tone="neutral">{{ __('admin.versions.state_current', ['date' => $ukDateTime->formatDate($row['updated_at'])]) }}</x-badge>
                                @elseif($row['state'] === 'pending')
                                    <x-badge tone="warning">{{ __('admin.versions.state_pending') }}</x-badge>
                                @else
                                    <x-badge tone="warning">{{ __('admin.versions.state_not_recorded') }}</x-badge>
                                @endif
                            </td>
                            <td>
                                @forelse($row['history'] as $entry)
                                    <div>
                                        {{ $entry->from_version === null
                                            ? __('admin.versions.history_installed', ['version' => $entry->to_version])
                                            : __('admin.versions.history_updated', ['from' => $entry->from_version, 'to' => $entry->to_version]) }}
                                        <small class="muted">{{ $ukDateTime->formatDate(\Illuminate\Support\Carbon::parse($entry->recorded_at)) }}</small>
                                    </div>
                                @empty
                                    <span class="muted">—</span>
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="muted">{{ __('admin.versions.footnote') }}</p>
    </section>
@endsection
