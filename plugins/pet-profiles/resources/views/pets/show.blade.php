@extends('layouts.app')

@section('title', $area->showOwnerMeta
    ? $area->showTitlePrefix.' #'.$pet->id.': '.$pet->name
    : $area->showTitlePrefix.': '.$pet->name)

@section('content')
    @if($area->showOwnerMeta)
        @inject('ukDateTime', \App\Support\UkDateTime::class)
    @endif
    <div class="panel">
        <span class="service-label {{ $area->serviceLabelClass }}">{{ $area->serviceLabel }}</span>
        <h1>{{ $pet->name }}</h1>
        <p class="muted">{{ $pet->species }} | Age: {{ $pet->age_years ?? 'n/a' }} | {{ \App\Support\WorkflowLabels::label('sex', $pet->sex) }} | {{ \App\Support\WorkflowLabels::label('neutering_status', $pet->neutering_status) }}</p>
        @if($area->showOwnerMeta)
            <dl class="ticket-meta">
                <div>
                    <dt>{{ __('pet_profiles::ui.show.blade.owner') }}</dt>
                    <dd>{{ $pet->user?->name ?? '—' }}@if($pet->user)<br><small class="muted">{{ $pet->user->email }}</small>@endif</dd>
                </div>
                <div>
                    <dt>{{ __('pet_profiles::ui.show.blade.created') }}</dt>
                    <dd>{{ $ukDateTime->formatDate($pet->created_at) ?? '—' }}</dd>
                </div>
                <div>
                    <dt>{{ __('pet_profiles::ui.show.blade.id') }}</dt>
                    <dd>#{{ $pet->id }}</dd>
                </div>
            </dl>
        @endif
        @if($pet->photo_path)
            <img src="{{ route($area->photoRouteName(), $pet) }}" alt="{{ $pet->name }}" class="record-photo">
        @endif
        @if($canUpdatePet)
            <form method="post" action="{{ route($area->updateRouteName(), $pet) }}" enctype="multipart/form-data" class="stack-spaced">
            @csrf
            @method('put')
            <div class="row">
                <div><label>{{ __('pet_profiles::ui.index.blade.name') }}</label><input name="name" value="{{ $pet->name }}"></div>
                <div><label>{{ __('pet_profiles::ui.index.blade.species') }}</label><input name="species" value="{{ $pet->species }}"></div>
                <div><label>{{ __('pet_profiles::ui.index.blade.age_years') }}</label><input type="number" min="0" max="80" name="age_years" value="{{ $pet->age_years }}"></div>
            </div>
            <div class="row">
                <div><label>{{ __('pet_profiles::ui.index.blade.sex') }}</label><select name="sex">@foreach(['male','female','unknown'] as $v)<option value="{{ $v }}" @selected($pet->sex===$v)>{{ \App\Support\WorkflowLabels::label('sex', $v) }}</option>@endforeach</select></div>
                <div><label>{{ __('pet_profiles::ui.index.blade.neutering_status') }}</label><select name="neutering_status">@foreach(['neutered','not_neutered','unknown'] as $v)<option value="{{ $v }}" @selected($pet->neutering_status===$v)>{{ \App\Support\WorkflowLabels::label('neutering_status', $v) }}</option>@endforeach</select></div>
                <div><label>{{ __('pet_profiles::ui.index.blade.photo') }}</label><input type="file" name="photo" accept="image/*"></div>
            </div>
            <label>{{ __('pet_profiles::ui.index.blade.health_issues') }}</label>
            <textarea name="health_issues">{{ $pet->health_issues }}</textarea>
            <div class="actions">
                <button type="submit">{{ __('pet_profiles::ui.show.blade.update_pet_profile') }}</button>
                <a href="{{ route($area->indexRouteName()) }}">{{ __('pet_profiles::ui.show.blade.back') }}</a>
            </div>
            </form>
        @else
            <a href="{{ route($area->indexRouteName()) }}">{{ __('pet_profiles::ui.show.blade.back') }}</a>
        @endif
    </div>
@endsection
