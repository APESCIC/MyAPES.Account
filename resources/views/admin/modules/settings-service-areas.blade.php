@extends('layouts.app')

@section('title', __('admin.plugins.plugin_settings').' | '.__('workspace.app_name'))

@section('content')
    <header class="page-heading module-settings-heading">
        <div>
            <p class="eyebrow">{{ strtoupper(str_replace('-', ' ', $subCoreKey)) }} · {{ strtoupper($moduleKey) }}</p>
            <h1>{{ __('admin.plugins.plugin_settings') }}</h1>
            <p>{{ __('admin.service_area_settings.intro') }}</p>
        </div>
        <div class="module-settings-toolbar actions">
            <a class="button button-secondary" href="{{ route('admin.modules.index') }}">{{ __('admin.plugins.back_to_plugins') }}</a>
        </div>
    </header>

    <section class="panel" data-service-area-settings>
        <h2>{{ $groupLabel }}</h2>
        <ul>
            @foreach(($settings[$groupKey] ?? []) as $area)
                <li>
                    {{ $area['label'] ?? $area['key'] }} <code>{{ $area['key'] }}</code>
                    @if($area['archived'] ?? false)
                        <x-badge tone="warning">{{ __('admin.managed_lists.archived') }}</x-badge>
                    @endif
                </li>
            @endforeach
        </ul>
        <div class="actions">
            @foreach($managedLists ?? [] as $managedList)
                <a href="{{ route('admin.modules.lists.show', [$subCoreKey, $moduleKey, $managedList->listKey]) }}" data-managed-list-link>{{ __('admin.service_area_settings.edit_areas') }}</a>
            @endforeach
        </div>
    </section>

    @if($canManage)
        <section class="panel">
            <h2>{{ __('admin.service_area_settings.reset_heading') }}</h2>
            <form method="post" action="{{ route('admin.modules.settings.update', [$subCoreKey, $moduleKey]) }}" class="stack-spaced">
                @csrf
                @method('put')
                <input type="hidden" name="version" value="{{ $record->lock_version }}">
                <input type="hidden" name="reset_defaults" value="1">
                <label>
                    <input type="checkbox" name="confirm_reset" value="1" required>
                    {{ __('admin.service_area_settings.confirm_reset') }}
                </label>
                <div class="actions">
                    <button type="submit" class="sg-btn-secondary">{{ __('admin.service_area_settings.reset') }}</button>
                </div>
            </form>
        </section>
    @endif
@endsection
