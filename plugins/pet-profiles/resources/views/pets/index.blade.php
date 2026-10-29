@extends('layouts.app')

@section('title', $area->indexTitle)

@section('content')
    <div class="panel-stack">
        <div class="panel">
            <span class="service-label {{ $area->serviceLabelClass }}">{{ $area->serviceLabel }}</span>
            <h1>{{ __('pet_profiles::ui.index.blade.pet_profiles') }}</h1>
        </div>
        <div class="panel" id="list">
            <h2>{{ __('pet_profiles::ui.index.blade.profiles') }}</h2>
            @if($pets->isEmpty())
                <x-mascot-tip
                    variant="empty"
                    title="{{ __('pet_profiles::ui.staff-empty-pet-select.blade.no_pet_profiles_are_available_yet') }}"
                    body="When a pet profile is added, it will appear here."
                />
            @else
                <table>
                    <thead><tr><th>{{ __('pet_profiles::ui.index.blade.name') }}</th><th>{{ __('pet_profiles::ui.index.blade.species') }}</th><th>{{ __('pet_profiles::ui.index.blade.age') }}</th><th>{{ __('pet_profiles::ui.index.blade.sex') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach($pets as $pet)
                        <tr>
                            <td>{{ $pet->name }}</td>
                            <td>{{ $pet->species }}</td>
                            <td>{{ $pet->age_years }}</td>
                            <td>{{ \App\Support\WorkflowLabels::label('sex', $pet->sex) }}</td>
                            <td><a href="{{ route($area->showRouteName(), $pet) }}">{{ __('pet_profiles::ui.index.blade.open') }}</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                {{ $pets->links() }}
            @endif
        </div>
        @if($canCreatePet)
            <div class="panel" id="create">
                <h2>{{ __('pet_profiles::ui.index.blade.add_pet_profile') }}</h2>
                @if($returnTo)
                    <p class="muted">{{ __('pet_profiles::ui.index.blade.after_you_save_this_pet_you_will_return_to_the_form_you') }}</p>
                @endif
                <form method="post" action="{{ route($area->storeRouteName()) }}" enctype="multipart/form-data">
                @csrf
                @if($returnTo)
                    <input type="hidden" name="return_to" value="{{ $returnTo }}">
                @endif
                <div class="row">
                    <div><label for="pet_name">{{ __('pet_profiles::ui.index.blade.name') }}</label><input id="pet_name" name="name"></div>
                    <div><label for="pet_species">{{ __('pet_profiles::ui.index.blade.species') }}</label><input id="pet_species" name="species"></div>
                    <div><label for="pet_age_years">{{ __('pet_profiles::ui.index.blade.age_years') }}</label><input id="pet_age_years" type="number" min="0" max="80" name="age_years"></div>
                </div>
                <div class="row">
                    <div><label for="pet_sex">{{ __('pet_profiles::ui.index.blade.sex') }}</label><select id="pet_sex" name="sex">@foreach(['male','female','unknown'] as $v)<option value="{{ $v }}">{{ \App\Support\WorkflowLabels::label('sex', $v) }}</option>@endforeach</select></div>
                    <div><label for="pet_neutering_status">{{ __('pet_profiles::ui.index.blade.neutering_status') }}</label><select id="pet_neutering_status" name="neutering_status">@foreach(['neutered','not_neutered','unknown'] as $v)<option value="{{ $v }}">{{ \App\Support\WorkflowLabels::label('neutering_status', $v) }}</option>@endforeach</select></div>
                    <div><label for="pet_photo">{{ __('pet_profiles::ui.index.blade.photo') }}</label><input id="pet_photo" type="file" name="photo" accept="image/*"></div>
                </div>
                <label for="pet_health_issues">{{ __('pet_profiles::ui.index.blade.health_issues') }}</label>
                <textarea id="pet_health_issues" name="health_issues"></textarea>
                <button type="submit">{{ __('pet_profiles::ui.index.blade.save_pet_profile') }}</button>
                </form>
            </div>
        @endif
    </div>
@endsection
