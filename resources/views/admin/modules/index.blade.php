@extends('layouts.app')

@section('title', 'Admin plugins | MyAPES Core')

@section('content')
    <header class="page-heading">
        <div>
            <p class="eyebrow">{{ __('admin.plugins.first_party_capability_registry') }}</p>
            <h1>{{ __('admin.plugins.admin_plugins') }}</h1>
            <p>{{ __('admin.plugins.each_plugin_lists_version_compatible_modules_dependencies_an') }}</p>
        </div>
    </header>

    <div class="module-registry" role="region" aria-label="{{ __('admin.plugins.plugin_compatibility_and_lifecycle_registry') }}">
        @foreach($plugins as $pluginRow)
            @php
                $pluginDef = $pluginRow['definition'];
                $compatible = implode(', ', $pluginRow['compatible_modules']);
                $pluginDeps = $pluginRow['dependencies'] === []
                    ? __('admin.access.none')
                    : implode(', ', $pluginRow['dependencies']);
            @endphp
            <section class="module-registry__subcore" aria-labelledby="plugin-{{ $pluginDef->key }}">
                <header class="module-registry__subcore-header">
                    <h2 id="plugin-{{ $pluginDef->key }}">{{ $pluginDef->name }}</h2>
                    <p class="module-registry__subcore-key">
                        <code>{{ $pluginDef->key }}</code>
                        · v{{ $pluginRow['manifest_version'] }}
                        · Compatible: {{ $compatible }}
                        · Depends on: {{ $pluginDeps }}
                    </p>
                    <p class="muted">{{ $pluginDef->description }}</p>
                </header>

                <ul class="module-registry__rows">
                    @foreach($pluginRow['cells'] as $cell)
                        @php
                            $subCore = $cell['sub_core'];
                            $definition = $cell['definition'];
                            $installation = $cell['installation'];
                            $status = $definition->codeStatus;
                            $shipped = $definition->isShipped();
                            $action = $installation === null
                                ? 'install'
                                : ($installation->enabled ? 'disable' : 'enable');
                            $stateClass = ! $shipped
                                ? $status->value
                                : ($installation === null
                                    ? $status->value
                                    : ($installation->enabled ? 'enabled' : 'disabled'));
                            $stateLabel = ! $shipped
                                ? $status->label()
                                : ($installation
                                    ? ($installation->enabled ? __('admin.plugins.enabled') : __('admin.plugins.disabled'))
                                    : __('admin.plugins.available'));
                            $dependencySummary = collect($cell['dependencies'])
                                ->map(fn (array $dependency): string => $dependency['key'].' ('.($dependency['enabled'] ? __('admin.plugins.enabled') : __('admin.access.unavailable')).')')
                                ->implode(', ');
                            if ($dependencySummary === '') {
                                $dependencySummary = __('admin.access.none');
                            }
                            $transitionLabel = $cell['transition_at']?->format('Y-m-d H:i') ?? 'Release default';
                            $actorLabel = $cell['actor_id'] ?? 'System';
                            $settingsDescriptor = $cell['settings'];
                            $supportsSettings = $settingsDescriptor->supportsSettings;
                            $recordCount = (int) ($cell['active_record_count'] ?? 0);
                            $depsLabel = $dependencySummary === __('admin.access.none') ? __('admin.access.none') : $dependencySummary;
                        @endphp

                        <li
                            class="module-registry__row module-registry__row--{{ $shipped ? 'shipped' : 'unavailable' }} module-registry__row--{{ $stateClass }}"
                            data-module-cell="{{ $definition->key() }}"
                            data-code-status="{{ $status->value }}"
                        >
                            <div class="module-registry__status-rail" aria-hidden="true"></div>
                            <div class="module-registry__card-body">
                                <div class="module-registry__row-main">
                                    <div class="module-registry__row-title">
                                        <span class="module-registry__module-name">{{ $subCore->name }}</span>
                                        <small><code>{{ $subCore->key }}</code></small>
                                    </div>
                                    <strong class="module-state module-state--{{ $stateClass }}">{{ $stateLabel }}</strong>
                                </div>

                                @if($shipped)
                                    <ul class="module-registry__metric-bar" aria-label="{{ __('admin.plugins.metrics_for') }} {{ $subCore->name }}">
                                        <li>
                                            <span class="module-registry__metric-label">{{ __('admin.plugins.records') }}</span>
                                            <strong>{{ $recordCount }}</strong>
                                        </li>
                                        <li>
                                            <span class="module-registry__metric-label">{{ __('admin.plugins.deps') }}</span>
                                            <strong title="{{ $depsLabel }}">{{ $dependencySummary === __('admin.access.none') ? '0' : collect($cell['dependencies'])->count() }}</strong>
                                        </li>
                                        <li>
                                            <span class="module-registry__metric-label">{{ __('admin.plugins.updated') }}</span>
                                            <strong title="{{ __('admin.plugins.actor') }} {{ $actorLabel }}">{{ $transitionLabel }}</strong>
                                        </li>
                                    </ul>
                                    @if($dependencySummary !== 'None')
                                        <p class="module-registry__deps muted">{{ $dependencySummary }}</p>
                                    @endif

                                    <div class="module-registry__row-actions">
                                        @if($supportsSettings)
                                            @can($settingsDescriptor->viewPermission)
                                                <a
                                                    class="module-registry__settings-link"
                                                    href="{{ $settingsDescriptor->settingsUrl() }}"
                                                >{{ $settingsDescriptor->navLabel }}</a>
                                            @endcan
                                        @else
                                            <span class="module-registry__settings-none muted">{{ __('admin.plugins.no_configurable_settings') }}</span>
                                        @endif

                                        @can('admin.modules.manage')
                                            <details class="module-registry__manage" data-module-manage>
                                                <summary>{{ __('admin.plugins.manage') }}</summary>
                                                <form
                                                    method="post"
                                                    action="{{ route('admin.modules.transition', [$subCore->key, $pluginDef->key]) }}"
                                                    class="module-action-form"
                                                    data-module-action-form
                                                >
                                                    @csrf
                                                    <input type="hidden" name="action" value="{{ $action }}">
                                                    @if($installation)
                                                        <input type="hidden" name="version" value="{{ $installation->lock_version }}">
                                                    @endif
                                                    <label>
                                                        <input type="checkbox" name="confirm_action" value="1" required>
                                                        {{ __('admin.plugins.confirm') }} {{ $action }}
                                                    </label>
                                                    <label>
                                                        <input type="checkbox" name="confirm_navigation" value="1" required>
                                                        {{ __('admin.plugins.confirm_navigation_change') }}
                                                    </label>
                                                    <button type="submit" class="button button-secondary">
                                                        {{ match ($action) { 'install' => __('admin.plugins.actions.install'), 'enable' => __('admin.plugins.actions.enable'), default => __('admin.plugins.actions.disable') } }}
                                                    </button>
                                                </form>
                                            </details>
                                        @endcan
                                    </div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
@endsection
