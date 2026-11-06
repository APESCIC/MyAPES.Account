@extends('layouts.app')

@section('title', 'APES Pet Care Clinic Consultation #'.$consultation->id)

@section('content')
    @inject('ukDateTime', \App\Support\UkDateTime::class)
    <div class="panel">
        <span class="service-label apes-petcare">{{ __('consultations::ui.index.blade.apes_pet_care_clinic') }}</span>
        <h1>Consultation #{{ $consultation->id }} - {{ $consultation->subject }}</h1>
        <p class="muted">Pet: {{ $consultation->petProfile->name }}</p>
        @if($consultation->needsVetReview() && ($canUpdate || $canAssign))
            <div class="alert" data-vet-review-notice>
                <p><strong>{{ __('consultations::ui.index.blade.needs_vet_review') }}</strong> {{ __('consultations::ui.show.blade.needs_vet_review_body') }}</p>
                @if($canUpdate)
                    <form id="consultation-vet-review-form" method="post" action="{{ route('petcare.consultations.vet-review', $consultation) }}">
                        @csrf
                        <button type="submit">{{ __('consultations::ui.show.blade.mark_reviewed') }}</button>
                    </form>
                @endif
            </div>
        @elseif($consultation->vet_reviewed_at !== null && ($canUpdate || $canAssign))
            <p class="muted" data-vet-reviewed>{{ __('consultations::ui.show.blade.vet_reviewed_by', ['name' => $consultation->vetReviewedBy?->name ?? __('consultations::ui.show.blade.not_recorded'), 'date' => $ukDateTime->format($consultation->vet_reviewed_at)]) }}</p>
        @endif
        <dl>
            <dt>{{ __('consultations::ui.index.blade.status') }}</dt>
            <dd>{{ \App\Support\WorkflowLabels::status($consultation->status) }}</dd>
            <dt>{{ __('consultations::ui.index.blade.scheduled_for') }}</dt>
            <dd>{{ $ukDateTime->format($consultation->scheduled_for) ?? 'Not scheduled' }}</dd>
            <dt>{{ __('consultations::ui.index.blade.notes') }}</dt>
            <dd>{{ $consultation->notes ?: 'No notes recorded.' }}</dd>
            <dt>{{ __('consultations::ui.show.blade.created_by') }}</dt>
            <dd data-consultation-created-by>{{ $consultation->createdBy?->name ?? __('consultations::ui.show.blade.not_recorded') }}</dd>
            <dt>{{ __('consultations::ui.show.blade.last_edited_by') }}</dt>
            <dd data-consultation-updated-by>{{ $consultation->updatedBy?->name ?? __('consultations::ui.show.blade.not_recorded') }}</dd>
            @if($canAssign)
                <dt>{{ __('consultations::ui.show.blade.assigned_staff') }}</dt>
                <dd>
                    @if($consultation->assignedTo)
                        {{ $consultation->assignedTo->name }}
                        @if($currentAssigneeUnavailable)
                            <span class="muted">{{ __('consultations::ui.show.blade.current_assignment_is_preserved_but_is_no_longer_eligib') }}</span>
                        @endif
                    @else
                        Unassigned
                    @endif
                </dd>
            @endif
        </dl>
        @if($canUpdate || $canClose)
            <form id="consultation-update-form" method="post" action="{{ route('petcare.consultations.update', $consultation) }}">
                @csrf
                @method('put')
                <div class="row">
                    @if(($consultation->status === 'closed' && $canClose) || ($consultation->status !== 'closed' && ($canUpdate || $canClose)))
                        <div>
                            <label>{{ __('consultations::ui.index.blade.status') }}</label>
                            <select name="status">
                                @foreach(['open','in_progress','closed'] as $status)
                                    @if($status === $consultation->status || ($status === 'closed' ? $canClose : ($consultation->status === 'closed' ? $canClose : $canUpdate)))
                                        <option value="{{ $status }}" @selected($consultation->status===$status)>{{ \App\Support\WorkflowLabels::status($status) }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                    @endif
                    @if($canUpdate)
                        <div>
                            <label>{{ __('consultations::ui.index.blade.scheduled_for_label') }} <span class="muted">{{ __('consultations::ui.index.blade.dd_mm_yyyy') }}</span></label>
                            <input type="text" name="scheduled_for" placeholder="{{ __('consultations::ui.index.blade.dd_mm_yyyy_hh_mm_ss') }}" autocomplete="off" value="{{ $ukDateTime->format($consultation->scheduled_for) }}">
                        </div>
                    @endif
                </div>
                @if($canUpdate)
                    <label>{{ __('consultations::ui.index.blade.notes') }}</label>
                    <x-rich-text-editor name="notes" :value="old('notes', $consultation->notes)" />
                @endif
                <div class="actions">
                    <button type="submit">{{ __('consultations::ui.show.blade.update_consultation') }}</button>
                </div>
            </form>
        @endif
        @if($canAssign)
            <form id="consultation-assignment-form" method="post" action="{{ route('petcare.consultations.update', $consultation) }}">
                @csrf
                @method('put')
                <label>{{ __('consultations::ui.show.blade.change_assigned_staff') }}</label>
                <select name="assigned_to">
                    <option disabled selected>{{ __('consultations::ui.show.blade.choose_an_assignment_change') }}</option>
                    <option value="">{{ __('consultations::ui.show.blade.clear_assignment') }}</option>
                    @foreach($staffUsers as $staffUser)
                        <option value="{{ $staffUser->id }}">{{ $staffUser->name }}</option>
                    @endforeach
                </select>
                <div class="actions">
                    <button type="submit">{{ __('consultations::ui.show.blade.update_assignment') }}</button>
                </div>
            </form>
        @endif
        <a href="{{ route('petcare.consultations.index') }}">{{ __('consultations::ui.show.blade.back') }}</a>
    </div>
@endsection
