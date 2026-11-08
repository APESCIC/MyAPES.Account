@extends('layouts.app')

@section('title', 'Admin modules | MyAPES Account')

@section('content')
    <header class="page-heading">
        <div>
            <p class="eyebrow">{{ __('admin.plugins.organisation_areas') }}</p>
            <h1>{{ __('admin.plugins.admin_modules') }}</h1>
            <p>{{ __('admin.plugins.enable_or_disable_entire_organisation_areas_each_module_list') }}</p>
        </div>
    </header>

    <ul class="module-registry__rows" role="list">
        @foreach($rows as $row)
            @php
                $manifest = $row['manifest'];
                $record = $row['record'];
                $enabled = (bool) $record->enabled;
                $uploadsOn = $record->uploads_enabled !== false;
                $plugins = $row['plugins'];
            @endphp
            <li class="module-registry__row module-registry__row--shipped module-registry__row--{{ $enabled ? 'enabled' : 'disabled' }}">
                <div class="module-registry__status-rail" aria-hidden="true"></div>
                <div class="module-registry__card-body">
                    <h2>{{ $manifest->name }}</h2>
                    <p class="muted"><code>{{ $manifest->slug }}</code> · {{ $manifest->routePrefix }}</p>
                    <p>{{ $manifest->description }}</p>
                    <p class="muted">State: {{ $enabled ? __('admin.plugins.enabled') : __('admin.plugins.disabled') }}</p>
                    <p class="muted" data-uploads-state="{{ $uploadsOn ? 'on' : 'off' }}">
                        {{ __('admin.plugins.uploads_state', ['state' => $uploadsOn ? __('admin.plugins.uploads_on') : __('admin.plugins.uploads_off')]) }}
                    </p>
                    @if($plugins !== [])
                        <ul class="module-registry__chips" aria-label="{{ __('admin.plugins.plugins_for') }} {{ $manifest->name }}">
                            @foreach($plugins as $plugin)
                                <li class="module-registry__chip">
                                    <strong class="module-state module-state--{{ $plugin['enabled'] ? 'enabled' : 'disabled' }}">
                                        {{ $plugin['enabled'] ? 'On' : 'Off' }}
                                    </strong>
                                    <span>{{ $plugin['name'] }}</span>
                                    <code class="muted">{{ $plugin['key'] }}</code>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @can('admin.modules.manage')
                        <div class="module-registry__row-actions">
                            <form method="post" action="{{ route('admin.organisation-modules.transition', $manifest->slug) }}">
                                @csrf
                                <input type="hidden" name="action" value="{{ $enabled ? 'disable' : 'enable' }}">
                                <button type="submit" class="button {{ $enabled ? 'button-secondary' : '' }}">
                                    {{ $enabled ? 'Disable' : 'Enable' }}
                                </button>
                            </form>
                            <form method="post" action="{{ route('admin.organisation-modules.uploads', $manifest->slug) }}">
                                @csrf
                                <input type="hidden" name="uploads_enabled" value="{{ $uploadsOn ? 0 : 1 }}">
                                <button type="submit" class="button button-secondary">
                                    {{ $uploadsOn ? __('admin.plugins.turn_uploads_off') : __('admin.plugins.turn_uploads_on') }}
                                </button>
                            </form>
                        </div>
                    @endcan
                </div>
            </li>
        @endforeach
    </ul>
@endsection
