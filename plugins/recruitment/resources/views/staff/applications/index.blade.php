@extends('layouts.app')

@section('title', 'Recruitment — Applications')

@section('content')
    @inject('ukDateTime', \App\Support\UkDateTime::class)
    @include('recruitment::_navigation')
    <div class="panel-stack">
        <div class="panel">
            <span class="service-label service-apes-cic">{{ __('recruitment::staff.staff.index.blade.apes_cic') }}</span>
            <h1>{{ __('recruitment::staff.staff._navigation.blade.applications') }}</h1>
            <p class="muted">{{ __('recruitment::staff.staff.index.blade.review_applications_for_apes_cic_recruitment_roles') }}</p>
            <x-mascot-tip />
        </div>

        <div class="panel" aria-label="{{ __('recruitment::staff.staff.index.blade.filter_applications') }}">
            <form method="get" action="{{ route('apes-cic.recruitment.applications.index') }}" class="stack-spaced">
                <div class="row">
                    <div>
                        <label for="filter_status">{{ __('recruitment::staff.staff.index.blade.status') }}</label>
                        <select id="filter_status" name="status">
                            <option value="">{{ __('recruitment::staff.staff.index.blade.all_statuses') }}</option>
                            @foreach($statuses as $status)
                                <option value="{{ $status }}" @selected($selectedStatus === $status)>
                                    {{ $statusLabels[$status] ?? $status }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="filter_category">{{ __('recruitment::staff.staff.index.blade.category') }}</label>
                        <select id="filter_category" name="category">
                            <option value="">{{ __('recruitment::staff.staff.index.blade.all_categories') }}</option>
                            @foreach($categories as $category)
                                <option value="{{ $category }}" @selected($selectedCategory === $category)>
                                    {{ \App\Support\WorkflowLabels::label('recruitment_category', $category) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="filter_role">{{ __('recruitment::staff.staff.index.blade.role') }}</label>
                        <select id="filter_role" name="role">
                            <option value="">{{ __('recruitment::staff.staff.index.blade.all_roles') }}</option>
                            @foreach($roles as $role)
                                <option value="{{ $role->id }}" @selected($selectedRoleId === $role->id)>
                                    {{ $role->title }} ({{ \App\Support\WorkflowLabels::status($role->status) }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="actions">
                    <button type="submit">{{ __('recruitment::staff.staff.index.blade.filter') }}</button>
                    <a href="{{ route('apes-cic.recruitment.applications.index') }}">{{ __('recruitment::staff.staff.index.blade.clear') }}</a>
                </div>
            </form>
        </div>

        <div class="panel" id="list" data-staff-recruitment-applications>
            <h2>{{ __('recruitment::staff.staff._navigation.blade.applications') }}</h2>
            @if($applications->isEmpty())
                <x-mascot-tip
                    variant="empty"
                    title="{{ __('recruitment::staff.staff.index.blade.no_applications_match') }}"
                    body="Adjust the filters, or wait for applicants to apply to open roles."
                />
            @else
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('recruitment::staff.staff.index.blade.role') }}</th>
                            <th>{{ __('recruitment::staff.staff.index.blade.applicant') }}</th>
                            <th>{{ __('recruitment::staff.staff.index.blade.status') }}</th>
                            <th>{{ __('recruitment::staff.staff.index.blade.submitted') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($applications as $application)
                            <tr>
                                <td>{{ $application->recruitmentRole?->title ?? '—' }}</td>
                                <td>
                                    {{ $application->user?->name ?? '—' }}
                                    @if($application->user)
                                        <br><small class="muted">{{ $application->user->email }}</small>
                                    @endif
                                </td>
                                <td>{{ $statusLabels[$application->status] ?? $application->status }}</td>
                                <td>{{ $ukDateTime->formatDate($application->submitted_at) ?? '—' }}</td>
                                <td>
                                    <a href="{{ route('apes-cic.recruitment.applications.show', $application) }}">{{ __('recruitment::staff.staff.index.blade.open') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                {{ $applications->links() }}
            @endif
        </div>
    </div>
@endsection
