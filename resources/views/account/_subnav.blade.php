@php($active = $activeAccountTab ?? 'account')
<nav class="admin-subnav" aria-label="{{ __('account.settings.tabs_label') }}">
    <a href="{{ route('account.show') }}" @if($active === 'account') aria-current="page" @endif>{{ __('account.settings.tabs.account') }}</a>
    <a href="{{ route('account.profile') }}" @if($active === 'profile') aria-current="page" @endif>{{ __('account.settings.tabs.profile') }}</a>
    <a href="{{ route('account.security') }}" @if($active === 'security') aria-current="page" @endif>{{ __('account.settings.tabs.security') }}</a>
    <a href="{{ route('account.privacy') }}" @if($active === 'privacy') aria-current="page" @endif>{{ __('account.settings.tabs.privacy') }}</a>
</nav>
