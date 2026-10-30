@props(['menu', 'title' => null, 'subtitle' => null])
@php($crumbs = $menu->breadcrumb($title))
<header class="sg-page-header" data-workspace-page-header>
    <div class="sg-page-header__bar">
        @if($crumbs !== [])
            <nav class="sg-breadcrumb" aria-label="{{ __('workspace.header.breadcrumb') }}">
                <ol>
                    @foreach($crumbs as $crumb)
                        <li>
                            @if(! $loop->first)
                                <i data-lucide="chevron-right" aria-hidden="true"></i>
                            @endif
                            @if($crumb['url'] !== null)
                                <a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
                            @else
                                <span aria-current="page">{{ $crumb['label'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </nav>
        @endif
        <div class="sg-page-header__tools">
            <button
                type="button"
                class="sg-search-pill"
                aria-keyshortcuts="Control+K Meta+K"
                aria-haspopup="dialog"
                data-workspace-palette-open
            >
                <i data-lucide="search" aria-hidden="true"></i>
                <span class="sg-search-pill__label">{{ __('workspace.header.search') }}</span>
                <kbd class="sg-kbd" aria-hidden="true" data-workspace-shortcut>Ctrl K</kbd>
            </button>
            <a href="{{ route('home') }}" class="sg-btn sg-btn-secondary sg-btn-sm" title="{{ __('workspace.header.view_site_hint') }}">
                <i data-lucide="external-link" aria-hidden="true"></i>
                {{ __('workspace.header.view_site') }}
            </a>
        </div>
    </div>
    @if(filled($title))
        <div>
            <h1 class="sg-page-header__title">{{ $title }}</h1>
            @if(filled($subtitle))
                <p class="sg-page-header__subtitle">{{ $subtitle }}</p>
            @endif
        </div>
    @endif
</header>
