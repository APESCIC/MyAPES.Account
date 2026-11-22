@php
    $tabRoute = $tabRoute ?? ($accountShowRoute ?? 'admin.users.show');
    $tabParam = $tabParam ?? 'user';
    $tabSubject = $tabSubject ?? $managedUser;
@endphp
<nav class="admin-subnav" aria-label="{{ __('admin.users.hub.tabs_label') }}" data-profile-hub-tabs>
    @foreach($hubTabs as $tabKey)
        <a
            href="{{ route($tabRoute, [$tabParam => $tabSubject, 'tab' => $tabKey]) }}"
            @if($hubTab === $tabKey) aria-current="page" @endif
        >{{ __("admin.users.hub.tabs.{$tabKey}") }}</a>
    @endforeach
</nav>
