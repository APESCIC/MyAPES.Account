@props(['menu'])
@if($menu->showsPanelSwitch())
    <nav class="sg-switch" aria-label="{{ __('workspace.nav.panel_switch') }}" data-workspace-panel-switch>
        @foreach($menu->switchablePanels() as $panel)
            <a href="{{ $panel->home()?->url }}" @if($panel->key === $menu->currentPanelKey) aria-current="true" @endif>{{ $panel->label }}</a>
        @endforeach
    </nav>
@endif
