@props(['menu'])
<div class="sg-rail sg-rail--drawer" data-workspace-rail>
    <div class="sg-rail__top">
        <a href="{{ auth()->check() ? route('dashboard') : route('home') }}" class="sg-brand">
            <img
                src="{{ asset('logos/myapes-mark-40x40.png') }}"
                width="40"
                height="40"
                alt=""
                class="sg-brand__mark"
            >
            <span class="sg-brand__text">
                <span class="sg-brand__name">{{ __('workspace.app_name') }}</span>
            </span>
        </a>
        <button
            type="button"
            class="sg-icon-btn"
            aria-label="{{ __('workspace.mobile.close') }}"
            data-workspace-close
        >
            <i data-lucide="x" aria-hidden="true"></i>
        </button>
    </div>

    @if($menu->showsPanelSwitch())
        <x-workspace.panel-switch :menu="$menu" />
    @endif

    @if($menu->user === null)
        <nav class="sg-drawer-nav" aria-label="{{ __('workspace.nav.label') }}" data-workspace-drawer-nav>
            <ul class="sg-nav-list">
                @foreach($menu->simpleLinks as $link)
                    <li>
                        <a
                            href="{{ $link->url }}"
                            class="sg-nav-link"
                            data-nav-key="{{ $link->key }}"
                            @if($menu->isActive($link)) aria-current="page" @endif
                        >
                            <i data-lucide="{{ $link->icon }}" aria-hidden="true"></i>
                            <span>{{ $link->label }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @else
        <div class="sg-drawer-accordion" data-workspace-drawer-accordion>
            @foreach($menu->topItems as $top)
                <details class="sg-drawer-accordion__item" @if($menu->activeTopItem()?->key === $top->key) open @endif>
                    <summary class="sg-drawer-accordion__summary">
                        <i data-lucide="{{ $top->icon }}" aria-hidden="true"></i>
                        <span>{{ $top->label }}</span>
                    </summary>
                    <div class="sg-drawer-accordion__body">
                        @foreach($top->groups as $group)
                            <p class="sg-nav-heading">{{ $group->heading }}</p>
                            <ul class="sg-nav-list">
                                @foreach($group->items as $item)
                                    <li>
                                        <a
                                            href="{{ $item->url }}"
                                            class="sg-nav-link"
                                            data-nav-key="{{ $item->key }}"
                                            @if($menu->isActive($item)) aria-current="page" @endif
                                        >
                                            <i data-lucide="{{ $item->icon }}" aria-hidden="true"></i>
                                            <span>{{ $item->label }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endforeach
                    </div>
                </details>
            @endforeach
        </div>

        @php
            $panel = $menu->currentPanel();
            $extraGroups = collect($panel?->groups ?? [])->filter(function ($group) use ($menu) {
                if (in_array($group->key, ['overview', 'support', 'tools'], true)) {
                    return true;
                }

                return $menu->currentPanelKey === \App\Support\Navigation\NavPanel::ADMIN;
            });
        @endphp
        @if($extraGroups->isNotEmpty())
            <nav class="sg-drawer-nav" aria-label="{{ __('workspace.nav.label') }}" data-workspace-drawer-nav>
                @foreach($extraGroups as $group)
                    @php($headingId = 'workspace-drawer-'.\Illuminate\Support\Str::slug($group->key))
                    <div>
                        <p id="{{ $headingId }}" class="sg-nav-heading">{{ $group->heading }}</p>
                        <ul class="sg-nav-list" aria-labelledby="{{ $headingId }}">
                            @foreach($group->items as $item)
                                <li>
                                    <a
                                        href="{{ $item->url }}"
                                        class="sg-nav-link"
                                        data-nav-key="{{ $item->key }}"
                                        @if($menu->isActive($item)) aria-current="page" @endif
                                    >
                                        <i data-lucide="{{ $item->icon }}" aria-hidden="true"></i>
                                        <span>{{ $item->label }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </nav>
        @endif
    @endif
</div>