<div class="panel" id="appearance">
    <h2>{{ __('workspace.theme.appearance_heading') }}</h2>
    <p class="muted">{{ __('workspace.theme.appearance_intro') }}</p>
    <x-theme-switcher
        variant="profile"
        id-prefix="profile-appearance"
        :appearance="$appearance ?? ['skin' => 'glassy', 'mode' => 'light', 'calm' => false]"
    />
</div>