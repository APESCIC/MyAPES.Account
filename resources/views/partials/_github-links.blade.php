@php
    $variant = $variant ?? 'footer';
    $user = auth()->user();
    $isAdmin = $user !== null && app(\App\Services\AuthorizationProfile::class)->isAdministrator($user);
    $isStaffSide = $user !== null && app(\App\Core\Accounts\AccountClassifier::class)->isStaffSide($user);
    $githubLinks = [
        [
            'label' => __('workspace.dock.github'),
            'url' => config('myapes.github.repository_url'),
            'icon' => 'github',
        ],
        [
            'label' => __('workspace.dock.open_an_issue'),
            'url' => config('myapes.github.new_issue_url'),
            'icon' => 'circle-plus',
        ],
        [
            'label' => __('workspace.dock.discussions'),
            'url' => config('myapes.github.discussions_url'),
            'icon' => 'messages-square',
        ],
    ];
@endphp

@if ($isAdmin)
    @if ($variant === 'dock')
        @foreach ($githubLinks as $link)
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
            @foreach ($githubLinks as $link)
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
@elseif ($isStaffSide)
    @if ($variant === 'dock')
        <a
            class="sg-icon-btn sg-icon-btn--sm"
            href="{{ route('staff.report-problem.create') }}"
            title="{{ __('workspace.dock.report_problem') }}"
        >
            <i data-lucide="life-buoy" aria-hidden="true"></i>
            <span class="sg-visually-hidden">{{ __('workspace.dock.report_problem') }}</span>
        </a>
    @else
        <ul class="github-links github-links--{{ $variant }}">
            <li>
                <a href="{{ route('staff.report-problem.create') }}">{{ __('workspace.dock.report_problem') }}</a>
            </li>
        </ul>
    @endif
@endif
