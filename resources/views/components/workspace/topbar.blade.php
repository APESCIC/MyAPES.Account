@props(['menu'])
<div class="sg-card sg-topbar" data-workspace-topbar>
    <button
        type="button"
        class="sg-icon-btn"
        aria-label="{{ __('workspace.mobile.open') }}"
        aria-expanded="false"
        aria-controls="workspace-drawer"
        data-workspace-menu-open
    >
        <i data-lucide="menu" aria-hidden="true"></i>
    </button>
    <x-workspace.brand :menu="$menu" />
    <button
        type="button"
        class="sg-icon-btn"
        aria-label="{{ __('workspace.mobile.search') }}"
        aria-haspopup="dialog"
        data-workspace-palette-open
    >
        <i data-lucide="search" aria-hidden="true"></i>
    </button>
</div>
