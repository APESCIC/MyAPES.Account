@props(['menu'])
<div class="sg-overlay sg-palette-overlay" data-workspace-palette hidden>
    <div
        class="sg-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="workspace-palette-title"
        data-workspace-palette-dialog
    >
        <h2 id="workspace-palette-title" class="sg-visually-hidden">{{ __('workspace.palette.title') }}</h2>
        <div class="sg-palette__search">
            <i data-lucide="search" aria-hidden="true"></i>
            <input
                type="text"
                class="sg-palette__input"
                role="combobox"
                aria-label="{{ __('workspace.mobile.search') }}"
                aria-expanded="true"
                aria-controls="workspace-palette-list"
                aria-autocomplete="list"
                autocomplete="off"
                spellcheck="false"
                placeholder="{{ __('workspace.palette.placeholder') }}"
                data-workspace-palette-input
            >
            <kbd class="sg-kbd" aria-hidden="true">{{ __('workspace.palette.close_hint') }}</kbd>
        </div>
        <ul
            id="workspace-palette-list"
            class="sg-palette__list"
            role="listbox"
            aria-label="{{ __('workspace.palette.results') }}"
            data-workspace-palette-list
        ></ul>
        <p class="sg-palette__empty" data-workspace-palette-empty hidden>{{ __('workspace.palette.empty') }}</p>
    </div>
    <script type="application/json" data-workspace-palette-index>{!! json_encode($menu->paletteCommands(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
</div>
