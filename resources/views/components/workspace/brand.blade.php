@props(['menu'])
<a href="{{ route('home') }}" class="sg-brand">
    <img
        src="{{ asset('logos/myapes-mark-40x40.png') }}"
        srcset="{{ asset('logos/myapes-mark-40x40.png') }} 1x, {{ asset('logos/myapes-mark-80x80.png') }} 2x, {{ asset('logos/myapes-mark-120x120.png') }} 3x"
        width="40"
        height="40"
        alt=""
        class="sg-brand__mark"
    >
    <span class="sg-brand__text">
        <span class="sg-brand__name">{{ __('workspace.app_name') }}</span>
        @if($menu->currentPanel())
            <span class="sg-brand__panel">{{ __('workspace.panel_subtitle', ['panel' => $menu->currentPanel()->label]) }}</span>
        @endif
    </span>
</a>
