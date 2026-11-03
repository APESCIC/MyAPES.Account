@extends('layouts.app')

@section('title', $list->label.' | '.__('workspace.app_name'))

@section('content')
    <header class="page-heading module-settings-heading">
        <div>
            <p class="eyebrow">{{ strtoupper(str_replace('-', ' ', $list->subCoreKey)) }} · {{ strtoupper($list->moduleKey) }}</p>
            <h1>{{ $list->label }}</h1>
            <p>{{ __('admin.managed_lists.intro') }}</p>
        </div>
        <div class="module-settings-toolbar actions">
            <a class="button button-secondary" href="{{ route('admin.modules.settings.edit', [$list->subCoreKey, $list->moduleKey]) }}">{{ __('admin.managed_lists.back_to_settings') }}</a>
        </div>
    </header>

    @error('item')
        <x-alert tone="error">{{ $message }}</x-alert>
    @enderror

    <section class="panel" data-managed-list="{{ $list->id() }}">
        <div class="module-settings-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.managed_lists.name') }}</th>
                        <th scope="col">{{ __('admin.managed_lists.key') }}</th>
                        <th scope="col">{{ __('admin.managed_lists.in_use') }}</th>
                        @if($canManage)
                            <th scope="col">{{ __('admin.managed_lists.actions') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $item)
                        @php($archived = (bool) ($item['archived'] ?? false))
                        <tr data-managed-list-item="{{ $item['key'] }}" @if($archived) data-archived @endif>
                            <td>
                                @if($canManage && ! $archived)
                                    <form method="post" action="{{ route('admin.modules.lists.update', [$list->subCoreKey, $list->moduleKey, $list->listKey, $item['key']]) }}" class="actions">
                                        @csrf
                                        @method('put')
                                        <label class="visually-hidden" for="item-label-{{ $item['key'] }}">{{ __('admin.managed_lists.name') }}</label>
                                        <input id="item-label-{{ $item['key'] }}" name="label" value="{{ $item['label'] }}" maxlength="255" required>
                                        <button type="submit" class="sg-btn-secondary">{{ __('admin.managed_lists.rename') }}</button>
                                    </form>
                                @else
                                    {{ $item['label'] }}
                                @endif
                                @if($archived)
                                    <x-badge tone="warning">{{ __('admin.managed_lists.archived') }}</x-badge>
                                @endif
                            </td>
                            <td><code>{{ $item['key'] }}</code></td>
                            <td>{{ $item['in_use'] }}</td>
                            @if($canManage)
                                <td>
                                    <div class="actions">
                                        @unless($loop->first)
                                            <form method="post" action="{{ route('admin.modules.lists.move', [$list->subCoreKey, $list->moduleKey, $list->listKey, $item['key']]) }}">
                                                @csrf
                                                <input type="hidden" name="direction" value="up">
                                                <button type="submit" class="sg-btn-secondary">{{ __('admin.managed_lists.move_up') }}</button>
                                            </form>
                                        @endunless
                                        @unless($loop->last)
                                            <form method="post" action="{{ route('admin.modules.lists.move', [$list->subCoreKey, $list->moduleKey, $list->listKey, $item['key']]) }}">
                                                @csrf
                                                <input type="hidden" name="direction" value="down">
                                                <button type="submit" class="sg-btn-secondary">{{ __('admin.managed_lists.move_down') }}</button>
                                            </form>
                                        @endunless
                                        @if($archived)
                                            <form method="post" action="{{ route('admin.modules.lists.restore', [$list->subCoreKey, $list->moduleKey, $list->listKey, $item['key']]) }}">
                                                @csrf
                                                <button type="submit" class="sg-btn-secondary">{{ __('admin.managed_lists.restore') }}</button>
                                            </form>
                                        @else
                                            <form method="post" action="{{ route('admin.modules.lists.destroy', [$list->subCoreKey, $list->moduleKey, $list->listKey, $item['key']]) }}">
                                                @csrf
                                                @method('delete')
                                                <button type="submit" class="sg-btn-secondary">{{ $item['in_use'] > 0 ? __('admin.managed_lists.archive') : __('admin.managed_lists.delete') }}</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="muted">{{ __('admin.managed_lists.archive_hint') }}</p>
    </section>

    @if($canManage)
        <section class="panel">
            <h2>{{ __('admin.managed_lists.add_heading') }}</h2>
            <form method="post" action="{{ route('admin.modules.lists.store', [$list->subCoreKey, $list->moduleKey, $list->listKey]) }}" class="actions">
                @csrf
                <label for="new-item-label">{{ __('admin.managed_lists.name') }}</label>
                <input id="new-item-label" name="label" value="{{ old('label') }}" maxlength="255" required>
                <button type="submit">{{ __('admin.managed_lists.add') }}</button>
            </form>
            <p class="muted">{{ __('admin.managed_lists.add_hint') }}</p>
        </section>
    @endif
@endsection
