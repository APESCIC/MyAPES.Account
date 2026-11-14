<nav class="admin-subnav" aria-label="{{ __('admin.emails.tabs.label') }}">
    <a href="{{ route('admin.emails.index') }}" @if($activeTab === 'templates') aria-current="page" @endif>{{ __('admin.emails.tabs.templates') }}</a>
    <a href="{{ route('admin.emails.log') }}" @if($activeTab === 'log') aria-current="page" @endif>{{ __('admin.emails.tabs.log') }}</a>
    <a href="{{ route('admin.emails.inbound.index') }}" @if($activeTab === 'inbound') aria-current="page" @endif>{{ __('admin.emails.tabs.inbound') }}</a>
    @can('admin.emails.view')
        <a href="{{ route('admin.emails.import.index') }}" data-email-import-tab @if($activeTab === 'import') aria-current="page" @endif>
            {{ __('admin.emails.tabs.import') }}
            @php($importBadge = app(\App\Core\Mail\Inbound\ImportQueue::class)->badgeCount())
            @if($importBadge > 0)
                <span class="sg-nav-badge" data-import-tab-badge>{{ $importBadge }}</span>
            @endif
        </a>
    @endcan
</nav>
