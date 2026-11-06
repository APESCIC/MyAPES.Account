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
            @if($reviewCount !== null)
                <p data-vet-review-filter>
                    @if($reviewFilter)
                        <a href="{{ route('petcare.consultations.index') }}#list">{{ __('consultations::ui.index.blade.all_consultations') }}</a>
                        · <strong>{{ __('consultations::ui.index.blade.needs_vet_review_count', ['count' => $reviewCount]) }}</strong>
                    @else
                        <strong>{{ __('consultations::ui.index.blade.all_consultations') }}</strong>
                        · <a href="{{ route('petcare.consultations.index', ['review' => 'needed']) }}#list">{{ __('consultations::ui.index.blade.needs_vet_review_count', ['count' => $reviewCount]) }}</a>
                    @endif
                </p>
            @endif
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
                            <td>
                                <span class="status">{{ \App\Support\WorkflowLabels::status($consultation->status) }}</span>
                                @if($reviewCount !== null && $consultation->needsVetReview())
                                    <span class="status" data-badge="needs-vet-review">{{ __('consultations::ui.index.blade.needs_vet_review') }}</span>
                                @endif
                            </td>
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
                    @if($fromTicket !== null)
                        <input type="hidden" name="support_ticket_id" value="{{ $fromTicket->id }}">
                        <p class="muted" data-consultation-from-ticket>{{ __('consultations::ui.index.blade.from_ticket', ['id' => $fromTicket->id, 'subject' => $fromTicket->subject]) }}</p>
                    @endif
                    <div class="row">
                        @include('pet-profiles::partials.pet-profile-select')
                        <div>
                            <label>{{ __('consultations::ui.index.blade.scheduled_for_label') }} <span class="muted">{{ __('consultations::ui.index.blade.dd_mm_yyyy') }}</span></label>
                            <input type="text" name="scheduled_for" placeholder="{{ __('consultations::ui.index.blade.dd_mm_yyyy_hh_mm_ss') }}" autocomplete="off">
                        </div>
                    </div>
                    <label>{{ __('consultations::ui.index.blade.subject') }}</label>
                    <input name="subject" value="{{ old('subject', $fromTicket?->subject) }}">
                    <label>{{ __('consultations::ui.index.blade.notes') }}</label>
                    <x-rich-text-editor name="notes" :value="old('notes')" />
                    <button type="submit">{{ __('consultations::ui.index.blade.create_consultation') }}</button>
                    </form>
                @endif
            </div>
        @elseif($requestConsultationUrl !== null)
            <div class="panel" id="request">
                <h2>{{ __('consultations::ui.index.blade.request_consultation') }}</h2>
                <p>{{ __('consultations::ui.index.blade.request_consultation_body') }}</p>
                <div class="actions">
                    <a href="{{ $requestConsultationUrl }}">{{ __('consultations::ui.index.blade.request_consultation_action') }}</a>
                </div>
            </div>
        @endif
    </div>
@endsection
