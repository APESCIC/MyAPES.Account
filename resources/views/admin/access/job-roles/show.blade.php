@extends('layouts.app')

@section('title', 'Job role | MyAPES Core')

@section('content')
    <div class="panel-stack">
        <section class="panel" aria-labelledby="managed-role-title">
            <p><a href="{{ route('admin.access.index', ['tab' => 'job-roles']) }}">{{ __('admin.access.back_to_job_roles') }}</a></p>
            <h1 id="managed-role-title">
                @if(\App\Support\DefaultJobRoles::isDefault($managedRole->name))
                    {{ \App\Support\DefaultJobRoles::title($managedRole->name) }}
                @else
                    {{ $managedRole->name }}
                @endif
            </h1>
            <dl class="admin-definition-list">
                <div><dt>{{ __('admin.access.ownership') }}</dt><dd>{{ \App\Support\DefaultJobRoles::isDefault($managedRole->name) ? 'Default job role' : 'Custom' }}</dd></div>
                <div><dt>{{ __('admin.access.assigned_users') }}</dt><dd>{{ $managedRole->users_count }}</dd></div>
                <div><dt>{{ __('admin.access.permissions') }}</dt><dd>{{ $managedRole->permissions_count }}</dd></div>
            </dl>
        </section>

        <section class="panel" aria-labelledby="role-permissions-title">
            <h2 id="role-permissions-title">{{ __('admin.access.permissions') }}</h2>
            <p class="muted">{{ __('admin.access.use_capability_packs_for_common_permission_sets_or_expand_ad') }}</p>

            @php
                $assignedGroups = $managedRole->permissions
                    ->groupBy(fn ($permission) => \App\Support\PermissionDescriptions::group($permission->name))
                    ->sortKeys();
            @endphp
            @forelse($assignedGroups as $groupName => $groupPermissions)
                <div class="permission-readable-group">
                    <h3>{{ $groupName }}</h3>
                    <ul class="permission-readable-list">
                        @foreach($groupPermissions as $permission)
                            <li>
                                <strong>{{ \App\Support\PermissionDescriptions::title($permission->name) }}</strong>
                                <p class="muted">{{ \App\Support\PermissionDescriptions::description($permission->name) }}</p>
                                <code>{{ $permission->name }}</code>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p>{{ __('admin.access.no_permissions') }}</p>
            @endforelse

            @can('admin.roles.manage')
                <form method="post" action="{{ route('admin.access.job-roles.update', $managedRole) }}">
                    @csrf
                    @method('put')
                    <label for="role-name">{{ __('admin.access.role_name') }}</label>
                    <input id="role-name" name="name" value="{{ $managedRole->name }}" required minlength="3" maxlength="64" pattern="[a-z][a-z0-9]*(?:-[a-z0-9]+)*">

                    <fieldset class="permission-choice-group">
                        <legend>{{ __('admin.access.capability_packs') }}</legend>
                        <ul class="permission-choice-list">
                            @foreach($packDefinitions as $packKey => $pack)
                                @php
                                    $state = $packStates[$packKey] ?? 'off';
                                @endphp
                                <li>
                                    <label class="permission-choice">
                                        <input type="checkbox"
                                               data-pack
                                               data-pack-permissions='@json($pack['permissions'])'
                                               @checked($state === 'on')
                                               @disabled($pack['permissions'] === [])>
                                        <span class="permission-choice__body">
                                            <span class="permission-choice__title">{{ $pack['title'] }}</span>
                                            @if($state === 'indeterminate')
                                                <span class="permission-choice__description muted">{{ __('admin.access.partially_selected_use_advanced_for_details') }}</span>
                                            @else
                                                <span class="permission-choice__description muted">{{ count($pack['permissions']) }} permissions</span>
                                            @endif
                                        </span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    </fieldset>

                    <details class="permission-advanced">
                        <summary>{{ __('admin.access.advanced_permissions') }}</summary>
                        @php
                            $editGroups = $permissions
                                ->groupBy(fn ($permission) => \App\Support\PermissionDescriptions::group($permission->name))
                                ->sortKeys();
                        @endphp
                        @foreach($editGroups as $groupName => $groupPermissions)
                            <fieldset class="permission-choice-group">
                                <legend>{{ $groupName }}</legend>
                                <ul class="permission-choice-list">
                                    @foreach($groupPermissions as $permission)
                                        <li>
                                            <label class="permission-choice">
                                                <input type="checkbox" name="permissions[]" value="{{ $permission->name }}" @checked($managedRole->permissions->contains($permission))>
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
                    </details>

                    <div class="actions"><button type="submit">{{ __('admin.access.update_role') }}</button></div>
                </form>

                <form class="stack-spaced" method="post" action="{{ route('admin.access.job-roles.destroy', $managedRole) }}">
                    @csrf
                    @method('delete')
                    <button class="danger-btn" type="submit">{{ __('admin.access.delete_custom_role') }}</button>
                </form>
            @endcan
        </section>
    </div>

    @include('admin.access.partials.pack-script')
@endsection
