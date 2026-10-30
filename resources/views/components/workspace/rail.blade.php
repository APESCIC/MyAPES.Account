@props(['menu'])
<div class="sg-rail" data-workspace-rail>
    <div class="sg-rail__top">
        <x-workspace.brand :menu="$menu" />
        <button
            type="button"
            class="sg-icon-btn"
            aria-label="{{ __('workspace.mobile.close') }}"
            data-workspace-close
            hidden
        >
            <i data-lucide="x" aria-hidden="true"></i>
        </button>
    </div>
    <x-workspace.panel-switch :menu="$menu" />
    <x-workspace.nav :menu="$menu" />
    <x-workspace.user-dock :menu="$menu" />
</div>
