@props(['menu', 'top'])
@php
    $panelId = 'workspace-mega-'.\Illuminate\Support\Str::slug($top->key);
    $isCurrent = $menu->activeTopItem()?->key === $top->key;
@endphp
<div class="sg-mega" data-workspace-mega>
    <button
        type="button"
        class="sg-mega__trigger"
        data-nav-key="{{ $top->key }}"
        data-workspace-mega-trigger
        aria-expanded="false"
        aria-haspopup="true"
        aria-controls="{{ $panelId }}"
        @if($isCurrent) aria-current="page" @endif
    >
        <i data-lucide="{{ $top->icon }}" aria-hidden="true"></i>
        <span>{{ $top->label }}</span>
        <i data-lucide="chevron-down" class="sg-mega__chevron" aria-hidden="true"></i>
    </button>
    <div
        id="{{ $panelId }}"
        class="sg-mega__panel"
        role="region"
        aria-label="{{ __('workspace.nav.mega_menu', ['module' => $top->label]) }}"
        data-workspace-mega-panel
        hidden
    >
        <div class="sg-mega__columns">
            @foreach($top->groups as $group)
                <section class="sg-mega__column" aria-labelledby="{{ $panelId }}-{{ $group->key }}">
                    <h3 id="{{ $panelId }}-{{ $group->key }}" class="sg-mega__heading">{{ $group->heading }}</h3>
                    <ul class="sg-mega__list">
                        @foreach($group->items as $item)
                            <li>
                                <a
                                    href="{{ $item->url }}"
                                    class="sg-mega__link"
                                    data-workspace-mega-link
                                    data-nav-key="{{ $item->key }}"
                                    @if($menu->isActive($item)) aria-current="page" @endif
                                >
                                    <span class="sg-mega__link-icon" aria-hidden="true">
                                        <i data-lucide="{{ $item->icon }}"></i>
                                    </span>
                                    <span class="sg-mega__link-text">
                                        <span class="sg-mega__link-label">{{ $item->label }}</span>
                                        @if(filled($item->description))
                                            <span class="sg-mega__link-desc">{{ $item->description }}</span>
                                        @endif
                                    </span>
                                    @if(($item->badge ?? null) !== null && $item->badge > 0)
                                        <span class="sg-nav-badge" data-nav-badge="{{ $item->key }}">{{ $item->badge }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    </div>
</div>