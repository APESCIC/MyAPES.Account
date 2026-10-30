@extends('layouts.app')

@section('title', 'Recruitment settings | MyAPES Account')

@section('content')
    <header class="page-heading module-settings-heading">
        <div>
            <p class="eyebrow">{{ strtoupper(str_replace('-', ' ', $subCoreKey)) }} · RECRUITMENT</p>
            <h1>{{ __('admin.plugins.recruitment_settings') }}</h1>
            <p>{{ __('admin.plugins.control_the_public_roles_board_and_whether_visitors_can_appl') }}</p>
        </div>
        <div class="module-settings-toolbar actions">
            <a class="button button-secondary" href="{{ route('admin.modules.index') }}">{{ __('admin.plugins.back_to_plugins') }}</a>
            @if($canManage)
                <button type="submit" form="module-settings-form">{{ __('admin.plugins.save_settings') }}</button>
            @endif
        </div>
    </header>

    @if(session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

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
                <h2>{{ __('admin.plugins.public_board') }}</h2>
                <p class="muted">{{ __('admin.plugins.when_off_the_public_roles_board_and_discovery_links_are_hidd') }}</p>
            </div>
            <label class="module-settings-toggle">
                <input
                    type="checkbox"
                    name="public_board_enabled"
                    value="1"
                    @checked(old('public_board_enabled', $settings['public_board_enabled'] ?? true))
                    @disabled(! $canManage)
                >
                {{ __('admin.plugins.show_public_roles_board') }}
            </label>
        </section>

        <section class="module-settings-group">
            <div class="module-settings-group__header">
                <h2>{{ __('admin.plugins.public_apply') }}</h2>
                <p class="muted">{{ __('admin.plugins.when_off_signed_in_visitors_can_browse_open_roles_but_cannot') }}</p>
            </div>
            <label class="module-settings-toggle">
                <input
                    type="checkbox"
                    name="public_apply_enabled"
                    value="1"
                    @checked(old('public_apply_enabled', $settings['public_apply_enabled'] ?? true))
                    @disabled(! $canManage)
                >
                {{ __('admin.plugins.allow_public_applications') }}
            </label>
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
            onsubmit="return confirm('Reset Recruitment settings to defaults?')"
        >
            @csrf
            @method('put')
            <input type="hidden" name="version" value="{{ $record->lock_version }}">
            <input type="hidden" name="reset_defaults" value="1">
            <p class="muted">{{ __('admin.plugins.restore_public_board_and_apply_toggles_to_their_default_both') }}</p>
            <label>
                <input type="checkbox" name="confirm_reset" value="1" required>
                {{ __('admin.plugins.confirm_reset_defaults') }}
            </label>
            <button type="submit" class="button button-secondary">{{ __('admin.plugins.reset_to_defaults') }}</button>
        </form>
    @endif
@endsection
