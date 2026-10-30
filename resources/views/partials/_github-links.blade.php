@php
    $variant = $variant ?? 'footer';
    $links = [
        [
            'label' => 'GitHub',
            'url' => config('myapes.github.repository_url'),
            'icon' => 'github',
        ],
        [
            'label' => 'Open an issue',
            'url' => config('myapes.github.new_issue_url'),
            'icon' => 'circle-plus',
        ],
        [
            'label' => 'Discussions',
            'url' => config('myapes.github.discussions_url'),
            'icon' => 'messages-square',
        ],
    ];
@endphp

@if ($variant === 'dock')
    @foreach ($links as $link)
        <a
            class="sg-icon-btn sg-icon-btn--sm"
            href="{{ $link['url'] }}"
            target="_blank"
            rel="noopener noreferrer"
            title="{{ $link['label'] }}"
        >
            <i data-lucide="{{ $link['icon'] }}" aria-hidden="true"></i>
            <span class="sg-visually-hidden">{{ $link['label'] }} ({{ __('workspace.dock.opens_in_new_tab') }})</span>
        </a>
    @endforeach
@else
    <ul class="github-links github-links--{{ $variant }}">
        @foreach ($links as $link)
            <li>
                <a
                    href="{{ $link['url'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                >{{ $link['label'] }}</a>
            </li>
        @endforeach
    </ul>
@endif
