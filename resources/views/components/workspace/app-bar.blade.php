@props(['menu'])
@php
    $brandUrl = auth()->check() ? route('dashboard') : route('home');
    $activeTop = $menu->activeTopItem();
@endphp
<header class="sg-app-bar sg-card" data-workspace-app-bar>
    <div class="sg-app-bar__start">
        <button
            type="button"
            class="sg-icon-btn sg-app-bar__menu"
            aria-label="{{ __('workspace.mobile.open') }}"
            aria-expanded="false"
            aria-controls="workspace-drawer"
            data-workspace-menu-open
        >
            <i data-lucide="menu" aria-hidden="true"></i>
        </button>
        <a href="{{ $brandUrl }}" class="sg-brand sg-app-bar__brand">
            <img
                src="{{ asset('logos/myapes-mark-40x40.png') }}"
                srcset="{{ asset('logos/myapes-mark-40x40.png') }} 1x, {{ asset('logos/myapes-mark-80x80.png') }} 2x, {{ asset('logos/myapes-mark-120x120.png') }} 3x"
                width="40"
                height="40"
                alt=""
                class="sg-brand__mark"
                loading="eager"
                fetchpriority="high"
                decoding="async"
            >
            <span class="sg-brand__text">
                <span class="sg-brand__name">{{ __('workspace.app_name') }}</span>
                @if($menu->user !== null && $menu->currentPanel())
                    <span class="sg-brand__panel">{{ __('workspace.panel_subtitle', ['panel' => $menu->currentPanel()->label]) }}</span>
                @endif
            </span>
        </a>
    </div>

    @if($menu->showsPanelSwitch())
        <x-workspace.panel-switch :menu="$menu" class="sg-app-bar__switch" />
    @endif

    <nav class="sg-app-bar__nav" aria-label="{{ __('workspace.nav.top_bar') }}" data-workspace-app-nav>
        @if($menu->user === null)
            <ul class="sg-app-bar__links">
                @foreach($menu->simpleLinks as $link)
                    <li>
                        <a
                            href="{{ $link->url }}"
                            class="sg-app-bar__link"
                            data-nav-key="{{ $link->key }}"
                            @if($menu->isActive($link)) aria-current="page" @endif
                        >
                            {{ $link->label }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @else
            <div class="sg-app-bar__modules" data-workspace-mega-root>
                @foreach($menu->topItems as $top)
                    <x-workspace.mega-menu :menu="$menu" :top="$top" />
                @endforeach
            </div>
        @endif
    </nav>

    <div class="sg-app-bar__end">
        <button
            type="button"
            class="sg-search-pill sg-app-bar__search"
            aria-keyshortcuts="Control+K Meta+K"
            aria-haspopup="dialog"
            aria-label="{{ __('workspace.header.search') }}"
            data-workspace-palette-open
        >
            <i data-lucide="search" aria-hidden="true"></i>
            <span class="sg-search-pill__label">{{ __('workspace.header.search') }}</span>
            <kbd class="sg-kbd" aria-hidden="true" data-workspace-shortcut>Ctrl K</kbd>
        </button>

        @auth
            <x-notifications.bell />
            <x-workspace.user-menu :menu="$menu" />
        @else
            <div class="sg-app-bar__guest-actions">
                <a href="{{ route('public.login') }}" class="sg-btn sg-btn-secondary sg-btn-sm">
                    <i data-lucide="log-in" aria-hidden="true"></i>
                    {{ __('workspace.dock.log_in') }}
                </a>
                @registrationOpen
                    <a href="{{ route('public.register') }}" class="sg-btn sg-btn-primary sg-btn-sm">
                        <i data-lucide="user-plus" aria-hidden="true"></i>
                        {{ __('workspace.dock.register') }}
                    </a>
                @endregistrationOpen
            </div>
        @endauth
    </div>
</header>

<div class="sg-drawer-source" hidden data-workspace-drawer-source>
    <x-workspace.drawer-nav :menu="$menu" />
</div>