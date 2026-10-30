@extends('layouts.app')

@section('title', 'Super Admin roles | MyAPES Account')

@section('content')
    <section class="panel" aria-labelledby="admin-roles-title">
        <h1 id="admin-roles-title">{{ __('admin.access.super_admin_roles') }}</h1>
        <p class="muted">{{ __('admin.access.protected_roles_sync_from_application_code_custom_roles_can_') }}</p>

        <form method="get" action="{{ route('admin.roles.index') }}">
            <label for="role-search">{{ __('admin.access.search_roles') }}</label>
            <input id="role-search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100">
            <div class="actions">
                <button type="submit">{{ __('admin.access.search') }}</button>
                <a href="{{ route('admin.roles.index') }}">{{ __('admin.access.clear_search') }}</a>
            </div>
        </form>
    </section>

    @can('admin.roles.manage')
        <section class="panel" aria-labelledby="create-role-title">
            <h2 id="create-role-title">{{ __('admin.access.create_custom_role') }}</h2>
            <form method="post" action="{{ route('admin.roles.store') }}">
                @csrf
                <label for="new-role-name">{{ __('admin.access.role_name') }}</label>
                <input id="new-role-name" name="name" required minlength="3" maxlength="64" pattern="[a-z][a-z0-9]*(?:-[a-z0-9]+)*" aria-describedby="role-name-help">
                <p id="role-name-help" class="muted">Use lower kebab case, for example <code>case-reviewer</code>.</p>
                @php
                    $createGroups = $permissions
                        ->groupBy(fn ($permission) => \App\Support\PermissionDescriptions::group($permission->name))
                        ->sortKeys();
                @endphp
                @foreach($createGroups as $groupName => $groupPermissions)
                    <fieldset class="permission-choice-group">
                        <legend>{{ $groupName }}</legend>
                        <ul class="permission-choice-list">
                            @foreach($groupPermissions as $permission)
                                <li>
                                    <label class="permission-choice">
                                        <input type="checkbox" name="permissions[]" value="{{ $permission->name }}">
                                        <span class="permission-choice__body">
                                            <span class="permission-choice__title">{{ \App\Support\PermissionDescriptions::title($permission->name) }}</span>
                                            <span class="permission-choice__description muted">{{ \App\Support\PermissionDescriptions::description($permission->name) }}</span>
                                            <code class="permission-choice__key">{{ $permission->name }}</code>
                                        </span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    </fieldset>
                @endforeach
                <div class="actions"><button type="submit">{{ __('admin.access.create_role') }}</button></div>
            </form>
        </section>
    @endcan

    <section class="panel" aria-labelledby="role-results-title">
        <h2 id="role-results-title">{{ __('admin.access.role_results') }}</h2>
        <table>
            <caption>{{ $roles->total() }} roles, ordered by protection state and name</caption>
            <thead><tr><th scope="col">{{ __('admin.access.role') }}</th><th scope="col">{{ __('admin.access.ownership') }}</th><th scope="col">{{ __('admin.access.permissions') }}</th><th scope="col">{{ __('admin.access.assigned_users') }}</th><th scope="col">{{ __('admin.access.action') }}</th></tr></thead>
            <tbody>
            @forelse($roles as $role)
                <tr>
                    <td>
                        @if(\App\Support\DefaultJobRoles::isDefault($role->name))
                            {{ \App\Support\DefaultJobRoles::title($role->name) }}
                            <code>{{ $role->name }}</code>
                        @else
                            <code>{{ $role->name }}</code>
                        @endif
                    </td>
                    <td>{{ $role->is_protected ? 'Protected by application code' : (\App\Support\DefaultJobRoles::isDefault($role->name) ? 'Default job role' : 'Custom') }}</td>
                    <td>{{ $role->permissions_count }}</td>
                    <td>{{ $role->users_count }}</td>
                    <td><a href="{{ route('admin.roles.show', $role) }}">{{ __('admin.access.view_role') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="5">{{ __('admin.access.no_roles_match_this_search') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $roles->links() }}
    </section>
@endsection
