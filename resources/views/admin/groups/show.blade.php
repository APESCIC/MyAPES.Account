@extends('layouts.app')

@section('title', 'Group members | MyAPES Core')

@section('content')
    <section class="panel" aria-labelledby="group-members-title">
        <p><a href="{{ route('admin.access.index', ['tab' => 'groups']) }}">{{ __('admin.access.back_to_groups') }}</a></p>
        <h1 id="group-members-title">{{ __('admin.access.members_of') }} <code>{{ $group->name }}</code></h1>
        <dl class="admin-definition-list">
            <div>
                <dt>{{ __('admin.access.catalogue_status') }}</dt>
                <dd>{{ $group->status === \App\Core\Accounts\DirectoryGroup::STATUS_PRESENT ? __('admin.access.present') : __('admin.access.missing') }}</dd>
            </div>
            <div>
                <dt>{{ __('admin.access.catalogue_member_count') }}</dt>
                <dd>{{ $group->member_count ?? 'Unknown' }}</dd>
            </div>
            <div>
                <dt>{{ __('admin.access.live_directory_members') }}</dt>
                <dd>
                    @if($directoryUnavailable)
                        {{ __('admin.access.unavailable') }}
                    @else
                        {{ count($members) }}
                    @endif
                </dd>
            </div>
        </dl>
        <p class="muted">{{ __('admin.access.membership_is_read_live_from_the_directory_this_page_does_no') }}</p>
    </section>

    <section class="panel" aria-labelledby="group-member-list-title">
        <h2 id="group-member-list-title">{{ __('admin.access.directory_members') }}</h2>
        @if($directoryUnavailable)
            <p role="alert">{{ __('admin.access.directory_membership_could_not_be_loaded_member_details_are_') }}</p>
        @else
            <table>
                <caption>{{ count($members) }} {{ __('admin.access.members_currently_in_group') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.access.name') }}</th>
                        <th scope="col">{{ __('admin.access.email') }}</th>
                        <th scope="col">{{ __('admin.access.job_title') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($members as $member)
                    <tr>
                        <td>{{ $member->name }}</td>
                        <td>{{ $member->email }}</td>
                        <td>{{ $member->jobTitle ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">{{ __('admin.access.no_members_are_currently_listed_for_this_group_in_the_direct') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        @endif
    </section>
@endsection
