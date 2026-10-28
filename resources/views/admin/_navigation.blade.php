@php
    $adminSearchEntries = app(\App\Services\AdminSearchCatalogue::class)->entriesFor(auth()->user());
@endphp
<div class="admin-shell-tools" data-admin-search>
    <nav class="admin-nav" aria-label="{{ __('admin.nav.admin_sections') }}">
        @can('admin.analytics.view')
            <a href="{{ route('admin.index') }}" @if(request()->routeIs('admin.index')) aria-current="page" @endif>
                <i data-lucide="layout-dashboard" aria-hidden="true"></i>
                <span>{{ __('admin.nav.overview') }}</span>
            </a>
        @endcan
        @can('admin.users.view')
            <a href="{{ route('admin.users.index', ['account_type' => 'public']) }}" @if(request()->routeIs('admin.users.*') && request('account_type') === 'public') aria-current="page" @endif>
                <i data-lucide="user-round" aria-hidden="true"></i>
                <span>{{ __('admin.nav.public_users') }}</span>
            </a>
            <a href="{{ route('admin.users.index', ['account_type' => 'staff']) }}" @if(request()->routeIs('admin.users.*') && request('account_type') === 'staff') aria-current="page" @endif>
                <i data-lucide="badge-check" aria-hidden="true"></i>
                <span>{{ __('admin.nav.staff') }}</span>
            </a>
        @endcan
        @canany(['admin.groups.view', 'admin.roles.view', 'admin.permissions.view'])
            <a href="{{ route('admin.access.index') }}" @if(request()->routeIs('admin.access.*', 'admin.groups.*', 'admin.roles.*', 'admin.permissions.*')) aria-current="page" @endif>
                <i data-lucide="shield-check" aria-hidden="true"></i>
                <span>{{ __('admin.nav.access') }}</span>
            </a>
        @endcanany
        @can('admin.modules.view')
            <a href="{{ route('admin.organisation-modules.index') }}" @if(request()->routeIs('admin.organisation-modules.*')) aria-current="page" @endif>
                <i data-lucide="building-2" aria-hidden="true"></i>
                <span>{{ __('admin.nav.modules') }}</span>
            </a>
            <a href="{{ route('admin.modules.index') }}" @if(request()->routeIs('admin.modules.*')) aria-current="page" @endif>
                <i data-lucide="puzzle" aria-hidden="true"></i>
                <span>{{ __('admin.nav.plugins') }}</span>
            </a>
        @endcan
        @can('admin.emails.view')
            <a href="{{ route('admin.emails.index') }}" @if(request()->routeIs('admin.emails.*')) aria-current="page" @endif>
                <i data-lucide="mail" aria-hidden="true"></i>
                <span>{{ __('admin.nav.emails') }}</span>
            </a>
        @endcan
        @can('admin.maintenance.manage')
            <a href="{{ route('admin.maintenance.index') }}" @if(request()->routeIs('admin.maintenance.*')) aria-current="page" @endif>
                <i data-lucide="circle-pause" aria-hidden="true"></i>
                <span>{{ __('admin.nav.maintenance') }}</span>
            </a>
        @endcan
    </nav>

    @if($adminSearchEntries !== [])
        <div class="admin-search">
            <label class="admin-search__label" for="admin-search-input">{{ __('admin.search.label') }}</label>
            <input
                id="admin-search-input"
                class="admin-search__input"
                type="search"
                data-admin-search-input
                autocomplete="off"
                placeholder="{{ __('admin.search.placeholder') }}"
                aria-controls="admin-search-results"
                aria-describedby="admin-search-hint"
            >
            <p id="admin-search-hint" class="admin-search__hint muted">{{ __('admin.search.hint') }}</p>
            <ul
                id="admin-search-results"
                class="admin-search__results"
                data-admin-search-results
                role="listbox"
                aria-label="{{ __('admin.search.results') }}"
                hidden
            ></ul>
            <p class="admin-search__empty muted" data-admin-search-empty hidden>{{ __('admin.search.empty') }}</p>
            <script type="application/json" data-admin-search-index>{!! json_encode($adminSearchEntries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        </div>
    @endif
</div>
