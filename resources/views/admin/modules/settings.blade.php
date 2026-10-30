@extends('layouts.app')

@section('title', 'Plugin settings | MyAPES Account')

@section('content')
    @php
        $idLabel = $moduleKey === 'tickets' ? 'Service area ID' : 'Category ID';
        $nameLabel = 'Display name';
    @endphp

    <header class="page-heading module-settings-heading">
        <div>
            <p class="eyebrow">{{ strtoupper(str_replace('-', ' ', $subCoreKey)) }} · {{ strtoupper($moduleKey) }}</p>
            <h1>{{ __('admin.plugins.plugin_settings') }}</h1>
            <p>Edit websites, {{ strtolower($groupLabel) }} and subcategory options used by forms.</p>
        </div>
        <div class="module-settings-toolbar actions">
            <a class="button button-secondary" href="{{ route('admin.modules.index') }}">{{ __('admin.plugins.back_to_plugins') }}</a>
            @if($canManage)
                <button type="submit" form="module-settings-form">{{ __('admin.plugins.save_settings') }}</button>
            @endif
        </div>
    </header>

    <form
        id="module-settings-form"
        method="post"
        action="{{ route('admin.modules.settings.update', [$subCoreKey, $moduleKey]) }}"
        class="module-settings-editor"
    >
        @csrf
        @method('put')
        <input type="hidden" name="version" value="{{ $record->lock_version }}">

        <section class="module-settings-group">
            <div class="module-settings-group__header">
                <h2>{{ __('admin.plugins.websites') }}</h2>
                <p class="muted">{{ __('admin.plugins.sites_offered_when_a_subcategory_requires_a_website') }}</p>
            </div>
            <div class="module-settings-table-wrap">
                <table class="module-settings-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.plugins.display_name') }}</th>
                            <th scope="col">{{ __('admin.plugins.website_id') }}</th>
                            <th scope="col">URL</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(($settings['websites'] ?? []) as $websiteIndex => $website)
                            <tr>
                                <td>
                                    <label class="visually-hidden" for="website-label-{{ $websiteIndex }}">{{ __('admin.plugins.display_name') }}</label>
                                    <input
                                        id="website-label-{{ $websiteIndex }}"
                                        name="websites[{{ $websiteIndex }}][label]"
                                        value="{{ old("websites.$websiteIndex.label", $website['label'] ?? '') }}"
                                        @disabled(! $canManage)
                                        required
                                    >
                                </td>
                                <td>
                                    <label class="visually-hidden" for="website-key-{{ $websiteIndex }}">{{ __('admin.plugins.website_id') }}</label>
                                    <input
                                        id="website-key-{{ $websiteIndex }}"
                                        name="websites[{{ $websiteIndex }}][key]"
                                        value="{{ old("websites.$websiteIndex.key", $website['key'] ?? '') }}"
                                        @disabled(! $canManage)
                                        required
                                    >
                                </td>
                                <td>
                                    <label class="visually-hidden" for="website-url-{{ $websiteIndex }}">URL</label>
                                    <input
                                        id="website-url-{{ $websiteIndex }}"
                                        name="websites[{{ $websiteIndex }}][url]"
                                        value="{{ old("websites.$websiteIndex.url", $website['url'] ?? '') }}"
                                        @disabled(! $canManage)
                                    >
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="module-settings-group">
            <div class="module-settings-group__header">
                <h2>{{ $groupLabel }}</h2>
                <p class="muted">{{ __('admin.plugins.groups_and_subcategories_shown_on_create_and_update_forms') }}</p>
            </div>

            @foreach(($settings[$groupKey] ?? []) as $groupIndex => $group)
                <details class="module-settings-accordion" @if($groupIndex === 0) open @endif>
                    <summary>
                        <span class="module-settings-accordion__title">
                            {{ old("$groupKey.$groupIndex.label", $group['label'] ?? 'Category') }}
                        </span>
                        <code class="module-settings-accordion__key">{{ old("$groupKey.$groupIndex.key", $group['key'] ?? '') }}</code>
                    </summary>

                    <div class="module-settings-accordion__body">
                        <div class="module-settings-inline-fields">
                            <div>
                                <label for="group-label-{{ $groupIndex }}">{{ $nameLabel }}</label>
                                <input
                                    id="group-label-{{ $groupIndex }}"
                                    name="{{ $groupKey }}[{{ $groupIndex }}][label]"
                                    value="{{ old("$groupKey.$groupIndex.label", $group['label'] ?? '') }}"
                                    @disabled(! $canManage)
                                    required
                                >
                            </div>
                            <div>
                                <label for="group-key-{{ $groupIndex }}">{{ $idLabel }}</label>
                                <input
                                    id="group-key-{{ $groupIndex }}"
                                    name="{{ $groupKey }}[{{ $groupIndex }}][key]"
                                    value="{{ old("$groupKey.$groupIndex.key", $group['key'] ?? '') }}"
                                    @disabled(! $canManage)
                                    required
                                >
                            </div>
                        </div>

                        <h3>{{ __('admin.plugins.subcategories') }}</h3>
                        <div class="module-settings-table-wrap">
                            <table class="module-settings-table module-settings-table--subs">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('admin.plugins.display_name') }}</th>
                                        <th scope="col">{{ __('admin.plugins.subcategory_id') }}</th>
                                        <th scope="col">{{ __('admin.plugins.requires_website') }}</th>
                                        <th scope="col">{{ __('admin.plugins.allow_attachments') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach(($group['subcategories'] ?? []) as $subIndex => $sub)
                                        <tr>
                                            <td>
                                                <label class="visually-hidden" for="sub-label-{{ $groupIndex }}-{{ $subIndex }}">{{ __('admin.plugins.display_name') }}</label>
                                                <input
                                                    id="sub-label-{{ $groupIndex }}-{{ $subIndex }}"
                                                    name="{{ $groupKey }}[{{ $groupIndex }}][subcategories][{{ $subIndex }}][label]"
                                                    value="{{ old("$groupKey.$groupIndex.subcategories.$subIndex.label", $sub['label'] ?? '') }}"
                                                    @disabled(! $canManage)
                                                    required
                                                >
                                            </td>
                                            <td>
                                                <label class="visually-hidden" for="sub-key-{{ $groupIndex }}-{{ $subIndex }}">{{ __('admin.plugins.subcategory_id') }}</label>
                                                <input
                                                    id="sub-key-{{ $groupIndex }}-{{ $subIndex }}"
                                                    name="{{ $groupKey }}[{{ $groupIndex }}][subcategories][{{ $subIndex }}][key]"
                                                    value="{{ old("$groupKey.$groupIndex.subcategories.$subIndex.key", $sub['key'] ?? '') }}"
                                                    @disabled(! $canManage)
                                                    required
                                                >
                                            </td>
                                            <td class="module-settings-table__flag">
                                                <label>
                                                    <input
                                                        type="checkbox"
                                                        name="{{ $groupKey }}[{{ $groupIndex }}][subcategories][{{ $subIndex }}][requires_website]"
                                                        value="1"
                                                        @checked(old("$groupKey.$groupIndex.subcategories.$subIndex.requires_website", $sub['requires_website'] ?? false))
                                                        @disabled(! $canManage)
                                                    >
                                                    <span class="visually-hidden">{{ __('admin.plugins.requires_website') }}</span>
                                                </label>
                                            </td>
                                            <td class="module-settings-table__flag">
                                                <label>
                                                    <input
                                                        type="checkbox"
                                                        name="{{ $groupKey }}[{{ $groupIndex }}][subcategories][{{ $subIndex }}][allows_attachments]"
                                                        value="1"
                                                        @checked(old("$groupKey.$groupIndex.subcategories.$subIndex.allows_attachments", $sub['allows_attachments'] ?? false))
                                                        @disabled(! $canManage)
                                                    >
                                                    <span class="visually-hidden">{{ __('admin.plugins.allow_attachments') }}</span>
                                                </label>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </details>
            @endforeach
        </section>

        @if($canManage)
            <div class="module-settings-sticky-actions">
                <button type="submit">{{ __('admin.plugins.save_settings') }}</button>
            </div>
        @endif
    </form>

    @if($canManage)
        <form
            method="post"
            action="{{ route('admin.modules.settings.update', [$subCoreKey, $moduleKey]) }}"
            class="module-settings-reset"
            onsubmit="return confirm('Reset all settings for this plugin to defaults?')"
        >
            @csrf
            @method('put')
            <input type="hidden" name="version" value="{{ $record->lock_version }}">
            <input type="hidden" name="reset_defaults" value="1">
            <p class="muted">Restore the shipped default websites and {{ strtolower($groupLabel) }}.</p>
            <label>
                <input type="checkbox" name="confirm_reset" value="1" required>
                {{ __('admin.plugins.confirm_reset_defaults') }}
            </label>
            <button type="submit" class="button button-secondary">{{ __('admin.plugins.reset_to_defaults') }}</button>
        </form>
    @endif
@endsection
