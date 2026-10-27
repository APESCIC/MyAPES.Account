@extends('layouts.app')

@section('title', 'Access | MyAPES Core')

@section('content')
    @include('admin._navigation')

    <div class="panel-stack">
        <section class="panel" aria-labelledby="admin-access-title">
            <h1 id="admin-access-title">{{ __('admin.nav.access') }}</h1>
            <p class="muted">{{ __('admin.access.manage_cloudron_group_mappings_job_role_capability_packs_and') }}</p>

            <nav class="admin-subnav" aria-label="{{ __('admin.access.access_sections') }}">
                @can('admin.groups.view')
                    <a href="{{ route('admin.access.index', ['tab' => 'groups']) }}"
                       @if(($activeTab ?? 'groups') === 'groups') aria-current="page" @endif>{{ __('admin.access.groups') }}</a>
                @endcan
                @can('admin.roles.view')
                    <a href="{{ route('admin.access.index', ['tab' => 'job-roles']) }}"
                       @if(($activeTab ?? '') === 'job-roles') aria-current="page" @endif>{{ __('admin.access.job_roles') }}</a>
                @endcan
                @can('admin.permissions.view')
                    <a href="{{ route('admin.access.index', ['tab' => 'permissions']) }}"
                       @if(($activeTab ?? '') === 'permissions') aria-current="page" @endif>{{ __('admin.access.permission_catalogue') }}</a>
                @endcan
            </nav>
        </section>

        @yield('access-content')
    </div>
@endsection
