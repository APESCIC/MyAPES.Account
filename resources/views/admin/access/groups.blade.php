@extends('admin.access.layout')

@section('access-content')
    @inject('ukDateTime', \App\Support\UkDateTime::class)

    <section class="panel" aria-labelledby="access-groups-title">
        <h2 id="access-groups-title">{{ __('admin.access.groups') }}</h2>
        <p class="muted">{{ __('admin.access.groups_intro') }}</p>

        <form method="get" action="{{ route('admin.access.index') }}">
            <input type="hidden" name="tab" value="groups">
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
                <a href="{{ route('admin.access.index', ['tab' => 'groups']) }}">{{ __('admin.access.clear_filters') }}</a>
            </div>
        </form>

        @can('admin.group-mappings.manage')
            <form class="stack-spaced" method="post" action="{{ route('admin.access.sync') }}">
                @csrf
                <button type="submit">{{ __('admin.access.sync_from_cloudron') }}</button>
            </form>
        @endcan
    </section>

    <section class="panel" aria-labelledby="group-results-title">
        <h2 id="group-results-title">{{ __('admin.access.preset_groups') }}</h2>
        <table>
            <caption>
                {{ $groups->total() }} preset directory groups, ordered by normalized name
                @if($catalogueSyncedAt)
                    — as of {{ $ukDateTime->format(\Illuminate\Support\Carbon::parse($catalogueSyncedAt)) }}
                @else
                    — last sync unknown
                @endif
            </caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('admin.access.group') }}</th>
                    <th scope="col">{{ __('admin.access.status') }}</th>
                    <th scope="col">{{ __('admin.access.members') }}</th>
                    <th scope="col">{{ __('admin.access.access_tier') }}</th>
                    <th scope="col">{{ __('admin.access.optional_job_role') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse($groups as $group)
                @php
                    $accessTier = $group->roles->first(fn ($role) => (bool) $role->pivot->is_immutable);
                    $jobRoleMappings = $group->roles->filter(fn ($role) => ! (bool) $role->pivot->is_immutable);
                    $memberCountLabel = $group->member_count ?? 'Unknown';
                @endphp
                <tr>
                    <td><code>{{ $group->name }}</code></td>
                    <td>{{ $group->status === \App\Core\Accounts\DirectoryGroup::STATUS_PRESENT ? __('admin.access.present') : __('admin.access.missing') }}</td>
                    <td>
                        <a href="{{ route('admin.groups.show', $group) }}">{{ $memberCountLabel }}</a>
                    </td>
                    <td>
                        @if($accessTier)
                            <code>{{ $accessTier->name }}</code>
                            <span class="status">{{ \App\Support\DirectoryGroupLabels::mappingLabel(true) }}</span>
                        @else
                            {{ __('admin.access.none') }}
                        @endif
                    </td>
                    <td>
                        @forelse($jobRoleMappings as $role)
                            <div>
                                <a href="{{ route('admin.access.job-roles.show', $role) }}">{{ \App\Support\DefaultJobRoles::isDefault($role->name) ? \App\Support\DefaultJobRoles::title($role->name) : $role->name }}</a>
                                @can('admin.group-mappings.manage')
                                    <form class="inline" method="post" action="{{ route('admin.access.mappings.destroy', $role->pivot->id) }}">
                                        @csrf
                                        @method('delete')
                                        <button class="danger-btn" type="submit">{{ __('admin.access.remove_job_role') }}</button>
                                    </form>
                                @endcan
                            </div>
                        @empty
                            {{ __('admin.access.none') }}
                        @endforelse
                        @can('admin.group-mappings.manage')
                            <form method="post" action="{{ route('admin.access.mappings.store', $group) }}">
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
        {{ $groups->appends(['tab' => 'groups'])->links() }}
    </section>
@endsection
