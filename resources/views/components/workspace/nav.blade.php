@props(['menu'])
<nav class="sg-nav" aria-label="{{ __('workspace.nav.label') }}" data-workspace-nav>
    @foreach($menu->currentPanel()?->groups ?? [] as $group)
        @php($headingId = 'workspace-nav-'.\Illuminate\Support\Str::slug($group->key))
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
