@extends('layouts.app')

@section('title', 'Super Admin groups | MyAPES Core')

@section('content')
    <section class="panel" aria-labelledby="admin-groups-title">
        <h1 id="admin-groups-title">{{ __('admin.access.super_admin_groups') }}</h1>
        <p class="muted">{{ __('admin.access.groups_intro') }}</p>

        <form method="get" action="{{ route('admin.groups.index') }}">
            <div class="row">
                <div>
                    <label for="group-search">{{ __('admin.access.search_group_name') }}</label>
                    <input id="group-search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100">
                </div>
                <div>
                    <label for="group-status">{{ __('admin.access.catalogue_status') }}</label>
                    <select id="group-status" name="status">
                        <option value="">{{ __('admin.access.all_statuses') }}</option>
                        <option value="present" @selected(($filters['status'] ?? '') === 'present')>{{ __('admin.access.present') }}</option>
                        <option value="missing" @selected(($filters['status'] ?? '') === 'missing')>{{ __('admin.access.missing') }}</option>
                    </select>
                </div>
            </div>
            <div class="actions">
                <button type="submit">{{ __('admin.access.apply_filters') }}</button>
                <a href="{{ route('admin.groups.index') }}">{{ __('admin.access.clear_filters') }}</a>
            </div>
        </form>

        @can('admin.group-mappings.manage')
            <form class="stack-spaced" method="post" action="{{ route('admin.groups.sync') }}">
                @csrf
                <button type="submit">{{ __('admin.access.queue_manual_directory_synchronization') }}</button>
            </form>
        @endcan
    </section>

    <section class="panel" aria-labelledby="group-results-title">
        <h2 id="group-results-title">{{ __('admin.access.preset_groups') }}</h2>
        <table>
            <caption>{{ $groups->total() }} preset directory groups, ordered by normalized name</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('admin.access.group') }}</th>
                    <th scope="col">{{ __('admin.access.source') }}</th>
                    <th scope="col">{{ __('admin.access.status') }}</th>
                    <th scope="col">{{ __('admin.access.members') }}</th>
                    <th scope="col">{{ __('admin.access.mapped_role') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse($groups as $group)
                <tr>
                    <td><code>{{ $group->name }}</code></td>
                    <td>
                        <span class="status" title="{{ \App\Support\DirectoryGroupLabels::sourceHint($group) }}">
                            {{ \App\Support\DirectoryGroupLabels::sourceLabel($group) }}
                        </span>
                    </td>
                    <td>{{ $group->status === \App\Core\Accounts\DirectoryGroup::STATUS_PRESENT ? __('admin.access.present') : __('admin.access.missing') }}</td>
                    <td>{{ $group->member_count ?? 'Unknown' }}</td>
                    <td>
                        @forelse($group->roles as $role)
                            <div>
                                <a href="{{ route('admin.roles.show', $role) }}">{{ \App\Support\DefaultJobRoles::isDefault($role->name) ? \App\Support\DefaultJobRoles::title($role->name) : $role->name }}</a>
                                <span class="status">{{ \App\Support\DirectoryGroupLabels::mappingLabel((bool) $role->pivot->is_immutable) }}</span>
                                @can('admin.group-mappings.manage')
                                    @if(! $role->pivot->is_immutable)
                                        <form class="inline" method="post" action="{{ route('admin.groups.mappings.destroy', $role->pivot->id) }}">
                                            @csrf
                                            @method('delete')
                                            <button class="danger-btn" type="submit">{{ __('admin.access.remove_job_role') }}</button>
                                        </form>
                                    @endif
                                @endcan
                            </div>
                        @empty
                            {{ __('admin.access.none') }}
                        @endforelse
                        @can('admin.group-mappings.manage')
                            <form method="post" action="{{ route('admin.groups.mappings.store', $group) }}">
                                @csrf
                                <label for="mapping-role-{{ $group->id }}">{{ __('admin.access.add_job_role_mapping') }}</label>
                                <select id="mapping-role-{{ $group->id }}" name="role_id" required>
                                    <option value="">{{ __('admin.access.choose_a_job_role') }}</option>
                                    @foreach($jobRoles as $jobRole)
                                        <option value="{{ $jobRole->id }}">{{ \App\Support\DefaultJobRoles::title($jobRole->name) }}</option>
                                    @endforeach
                                </select>
                                <button type="submit">{{ __('admin.access.add_mapping') }}</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="5">{{ __('admin.access.no_preset_directory_groups_match_these_filters') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $groups->links() }}
    </section>
@endsection
