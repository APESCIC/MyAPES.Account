@extends('layouts.app')

@section('title', 'APES Pet Care Clinic Consultations')

@section('content')
    @inject('ukDateTime', \App\Support\UkDateTime::class)
    <div class="panel-stack">
        <div class="panel">
            <span class="service-label apes-petcare">{{ __('consultations::ui.index.blade.apes_pet_care_clinic') }}</span>
            <h1>{{ __('consultations::ui.index.blade.consultation_management') }}</h1>
        </div>
        <div class="panel" id="list">
            <h2>{{ __('consultations::ui.index.blade.consultations') }}</h2>
            @if($consultations->isEmpty())
                <x-mascot-tip
                    variant="empty"
                    title="{{ __('consultations::ui.index.blade.no_consultations_yet') }}"
                    body="{{ __('consultations::ui.index.blade.no_consultations_body') }}"
                />
            @else
                <table>
                    <thead><tr><th>{{ __('consultations::ui.index.blade.id') }}</th><th>{{ __('consultations::ui.index.blade.subject') }}</th><th>{{ __('consultations::ui.index.blade.status') }}</th><th>{{ __('consultations::ui.index.blade.pet') }}</th><th>{{ __('consultations::ui.index.blade.scheduled') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach($consultations as $consultation)
                        <tr>
                            <td>#{{ $consultation->id }}</td>
                            <td>{{ $consultation->subject }}</td>
                            <td><span class="status">{{ \App\Support\WorkflowLabels::status($consultation->status) }}</span></td>
                            <td>{{ $consultation->petProfile->name }}</td>
                            <td>{{ $ukDateTime->format($consultation->scheduled_for) }}</td>
                            <td><a href="{{ route('petcare.consultations.show', $consultation) }}">{{ __('consultations::ui.index.blade.open') }}</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                {{ $consultations->links() }}
            @endif
        </div>
        @if($canCreate)
            <div class="panel" id="create">
                <h2>{{ __('consultations::ui.index.blade.create_consultation') }}</h2>
                @if($showEmptyPetSelect)
                    @include('pet-profiles::partials.staff-empty-pet-select')
                @else
                    <form method="post" action="{{ route('petcare.consultations.store') }}">
                    @csrf
                    <div class="row">
                        @include('pet-profiles::partials.pet-profile-select')
                        <div>
                            <label>{{ __('consultations::ui.index.blade.scheduled_for_label') }} <span class="muted">{{ __('consultations::ui.index.blade.dd_mm_yyyy') }}</span></label>
                            <input type="text" name="scheduled_for" placeholder="{{ __('consultations::ui.index.blade.dd_mm_yyyy_hh_mm_ss') }}" autocomplete="off">
                        </div>
                    </div>
                    <label>{{ __('consultations::ui.index.blade.subject') }}</label>
                    <input name="subject">
                    <label>{{ __('consultations::ui.index.blade.notes') }}</label>
                    <textarea name="notes"></textarea>
                    <button type="submit">{{ __('consultations::ui.index.blade.create_consultation') }}</button>
                    </form>
                @endif
            </div>
        @endif
    </div>
@endsection
