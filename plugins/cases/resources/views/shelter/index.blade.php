@extends('layouts.app')

@section('title', 'Shelter Cases')

@section('content')
    <div class="panel-stack">
        <div class="panel">
            <span class="service-label apes-shelter">{{ __('cases::ui.index.blade.apes_shelter_and_rescue') }}</span>
            <h1>{{ __('cases::ui.index.blade.case_management') }}</h1>
            <p class="muted">{{ __('cases::ui.index.blade.track_adoption_surrender_rescue_and_fostering_workflows') }}</p>
        </div>
        <div class="panel" id="list">
            <h2>{{ __('cases::ui.index.blade.cases') }}</h2>
            <table>
                <thead><tr><th>{{ __('cases::ui.index.blade.id') }}</th><th>{{ __('cases::ui.index.blade.title') }}</th><th>{{ __('cases::ui.index.blade.type') }}</th><th>{{ __('cases::ui.index.blade.status') }}</th><th>{{ __('cases::ui.index.blade.pet') }}</th><th></th></tr></thead>
                <tbody>
                @foreach($cases as $case)
                    <tr>
                        <td>#{{ $case->id }}</td>
                        <td>{{ $case->title }}</td>
                        <td>{{ $case->case_type }}</td>
                        <td><span class="status">{{ $case->status }}</span></td>
                        <td>{{ $case->petProfile->name }}</td>
                        <td><a href="{{ route('shelter.cases.show', $case) }}">{{ __('cases::ui.index.blade.open') }}</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            {{ $cases->links() }}
        </div>
        @if($canCreateCase)
            <div class="panel" id="create">
                <h2>{{ __('cases::ui.index.blade.create_case') }}</h2>
                @if($showEmptyPetSelect)
                    @include('pet-profiles::partials.staff-empty-pet-select')
                @else
                    <form method="post" action="{{ route('shelter.cases.store') }}">
                    @csrf
                    <div class="row">
                        @include('pet-profiles::partials.pet-profile-select')
                        <div>
                            <label>{{ __('cases::ui.index.blade.case_type') }}</label>
                            <select name="case_type">@foreach(['adoption','surrender','rescue','fostering'] as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach</select>
                        </div>
                    </div>
                    <label>{{ __('cases::ui.index.blade.title') }}</label>
                    <input name="title">
                    <label>{{ __('cases::ui.index.blade.details') }}</label>
                    <textarea name="details"></textarea>
                    <button type="submit">{{ __('cases::ui.index.blade.create_case') }}</button>
                    </form>
                @endif
            </div>
        @endif
    </div>
@endsection
