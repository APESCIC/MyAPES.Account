@extends('layouts.app')

@section('title', __('admin.access.permissions_page_title'))

@section('content')
    <section class="panel" aria-labelledby="admin-permissions-title">
        <h1 id="admin-permissions-title">{{ __('admin.access.super_admin_permissions') }}</h1>
        <p class="muted">{{ __('admin.access.permissions_intro') }}</p>
        <form method="get" action="{{ route('admin.permissions.index') }}" class="permission-filter-form">
            <div class="row">
                <div>
                    <label for="permission-search">{{ __('admin.access.search') }}</label>
                    <input id="permission-search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="{{ __('admin.access.title_or_key_e_g_users_or_admin_modules') }}">
                </div>
                <div>
                    <label for="permission-group">{{ __('admin.access.group') }}</label>
                    <select id="permission-group" name="group">
                        <option value="">{{ __('admin.access.all_groups') }}</option>
                        @foreach($groups as $group)
                            <option value="{{ $group }}" @selected(($filters['group'] ?? '') === $group)>{{ $group }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="actions">
                <button type="submit">{{ __('admin.access.apply_filters') }}</button>
                <a href="{{ route('admin.permissions.index') }}">{{ __('admin.access.clear') }}</a>
            </div>
        </form>
    </section>

    <section class="panel" aria-labelledby="permission-results-title">
        <h2 id="permission-results-title">{{ __('admin.access.permission_catalogue') }}</h2>
        <p class="muted">{{ $permissions->total() }} permissions match these filters.</p>

        @php
            $grouped = $permissions->getCollection()
                ->groupBy(fn ($permission) => \App\Support\PermissionDescriptions::group($permission->name))
                ->sortKeys();
        @endphp

        @forelse($grouped as $groupName => $groupPermissions)
            <div class="permission-catalogue-group">
                <h3>{{ $groupName }}</h3>
                <ul class="permission-readable-list">
                    @foreach($groupPermissions as $permission)
                        <li>
                            <div class="permission-readable-heading">
                                <strong>{{ \App\Support\PermissionDescriptions::title($permission->name) }}</strong>
                                <code>{{ $permission->name }}</code>
                            </div>
                            <p class="muted">{{ \App\Support\PermissionDescriptions::description($permission->name) }}</p>
                            <p>
                                <span class="muted">{{ __('admin.access.assigned_roles') }}</span>
                                @forelse($permission->roles as $role)
                                    <a href="{{ route('admin.roles.show', $role) }}">{{ $role->name }}</a>@if(! $loop->last), @endif
                                @empty
                                    {{ __('admin.access.none') }}
                                @endforelse
                            </p>
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            <p>{{ __('admin.access.no_permissions_match_this_search') }}</p>
        @endforelse

        {{ $permissions->links() }}
    </section>
@endsection
