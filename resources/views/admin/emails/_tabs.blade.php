<nav class="admin-subnav" aria-label="{{ __('admin.emails.tabs.label') }}">
    <a href="{{ route('admin.emails.index') }}" @if($activeTab === 'templates') aria-current="page" @endif>{{ __('admin.emails.tabs.templates') }}</a>
    <a href="{{ route('admin.emails.log') }}" @if($activeTab === 'log') aria-current="page" @endif>{{ __('admin.emails.tabs.log') }}</a>
    <a href="{{ route('admin.emails.inbound.index') }}" @if($activeTab === 'inbound') aria-current="page" @endif>{{ __('admin.emails.tabs.inbound') }}</a>
</nav>
