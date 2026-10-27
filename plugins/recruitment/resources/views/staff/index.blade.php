@extends('layouts.app')

@section('title', 'Recruitment — Roles')

@section('content')
    @include('recruitment::_navigation')
    <div class="panel-stack">
        <div class="panel">
            <span class="service-label service-apes-cic">{{ __('recruitment::staff.staff.index.blade.apes_cic') }}</span>
            <h1>{{ __('recruitment::staff.staff._navigation.blade.roles') }}</h1>
            <p class="muted">{{ __('recruitment::staff.staff.index.blade.staff_volunteering_and_student_openings_for_apes_cic') }}</p>
            <x-mascot-tip />
        </div>
        <div class="panel" id="list">
            <h2>{{ __('recruitment::staff.staff._navigation.blade.roles') }}</h2>
            @if($roles->isEmpty())
                <x-mascot-tip
                    variant="empty"
                    title="{{ __('recruitment::staff.staff.index.blade.no_recruitment_roles_yet') }}"
                    body="When a role is created, it will appear here."
                />
            @else
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('recruitment::staff.staff.index.blade.title') }}</th>
                            <th>{{ __('recruitment::staff.staff.index.blade.category') }}</th>
                            <th>{{ __('recruitment::staff.staff.index.blade.status') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($roles as $role)
                            <tr>
                                <td>{{ $role->title }}</td>
                                <td>{{ $role->category }}</td>
                                <td>{{ $role->status }}</td>
                                <td>
                                    <a href="{{ route('apes-cic.recruitment.show', $role) }}">{{ __('recruitment::staff.staff.index.blade.open') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                {{ $roles->links() }}
            @endif
        </div>
        @if($canCreate)
            <div class="panel" id="create">
                <h2>{{ __('recruitment::staff.staff.index.blade.create_role') }}</h2>
                <form method="post" action="{{ route('apes-cic.recruitment.store') }}">
                    @csrf
                    <div class="row">
                        <div>
                            <label for="role_title">{{ __('recruitment::staff.staff.index.blade.title') }}</label>
                            <input id="role_title" name="title" value="{{ old('title') }}" required>
                        </div>
                        <div>
                            <label for="role_category">{{ __('recruitment::staff.staff.index.blade.category') }}</label>
                            <select id="role_category" name="category" required>
                                @foreach($categories as $category)
                                    <option value="{{ $category }}" @selected(old('category') === $category)>
                                        {{ $category }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div>
                            <label for="role_location">{{ __('recruitment::staff.staff.index.blade.location') }}</label>
                            <input id="role_location" name="location" value="{{ old('location') }}">
                        </div>
                        <div>
                            <label for="role_commitment">{{ __('recruitment::staff.staff.index.blade.commitment') }}</label>
                            <input id="role_commitment" name="commitment" value="{{ old('commitment') }}">
                        </div>
                    </div>
                    <label for="role_summary">{{ __('recruitment::staff.staff.index.blade.summary') }}</label>
                    <input id="role_summary" name="summary" value="{{ old('summary') }}">
                    <label for="role_description">{{ __('recruitment::staff.staff.index.blade.description') }}</label>
                    <textarea id="role_description" name="description" required>{{ old('description') }}</textarea>
                    <button type="submit">{{ __('recruitment::staff.staff.index.blade.save_recruitment_role') }}</button>
                </form>
            </div>
        @endif
    </div>
@endsection
