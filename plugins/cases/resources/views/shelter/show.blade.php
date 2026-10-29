@extends('layouts.app')

@section('title', 'Shelter Case #'.$case->id)

@section('content')
    <div class="panel">
        <span class="service-label apes-shelter">{{ __('cases::ui.index.blade.apes_shelter_and_rescue') }}</span>
        <h1>Case #{{ $case->id }} - {{ $case->title }}</h1>
        <p class="muted">Pet: {{ $case->petProfile->name }} | Type: {{ \App\Support\WorkflowLabels::label('case_type', $case->case_type) }}</p>
        <p class="muted">Status: {{ \App\Support\WorkflowLabels::status($case->status) }}</p>
        @if(filled($case->details))
            <div>
                <strong>{{ __('cases::ui.index.blade.details') }}</strong>
                <p>{{ $case->details }}</p>
            </div>
        @endif
        @if($canUpdateCase || $canCloseCase)
            <form id="case-metadata-form" method="post" action="{{ route('shelter.cases.update', $case) }}">
                @csrf
                @method('put')
                @if($canUpdateCase)
                    <div class="row">
                        <div>
                            <label for="case_type">{{ __('cases::ui.index.blade.case_type') }}</label>
                            <select id="case_type" name="case_type">
                                @foreach($caseTypes as $type)
                                    <option value="{{ $type }}" @selected($case->case_type === $type)>{{ \App\Support\WorkflowLabels::label('case_type', $type) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="title">{{ __('cases::ui.index.blade.title') }}</label>
                            <input id="title" name="title" value="{{ $case->title }}">
                        </div>
                    </div>
                @endif
                <div>
                    <label for="status">{{ __('cases::ui.index.blade.status') }}</label>
                    <select id="status" name="status">
                        @foreach($statuses as $status)
                            @php
                                $isCurrentStatus = $status === $case->status;
                                $crossesClosedBoundary = $status === 'closed'
                                    || $case->status === 'closed';
                            @endphp
                            @if($isCurrentStatus
                                || ($crossesClosedBoundary && $canCloseCase)
                                || (! $crossesClosedBoundary && $canUpdateCase))
                                <option value="{{ $status }}" @selected($isCurrentStatus)>{{ \App\Support\WorkflowLabels::status($status) }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
                @if($canUpdateCase)
                    <label for="details">{{ __('cases::ui.index.blade.details') }}</label>
                    <textarea id="details" name="details">{{ $case->details }}</textarea>
                @endif
                <button type="submit">{{ __('cases::ui.show.blade.update_case') }}</button>
            </form>
        @endif
        @if($canChangeAssignment)
            <form id="case-assignment-form" method="post" action="{{ route('shelter.cases.update', $case) }}">
                @csrf
                @method('put')
                <div>
                    <label for="assigned_to">{{ __('cases::ui.show.blade.assigned_staff') }}</label>
                    <select id="assigned_to" name="assigned_to">
                        <option value="">{{ __('cases::ui.show.blade.unassigned') }}</option>
                        @foreach($staffUsers as $staffUser)
                            <option value="{{ $staffUser->id }}" @selected((int)$case->assigned_to === (int)$staffUser->id)>{{ $staffUser->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit">{{ __('cases::ui.show.blade.update_assignment') }}</button>
            </form>
        @endif
        <div class="actions">
            <a href="{{ route('shelter.cases.index') }}">{{ __('cases::ui.show.blade.back') }}</a>
        </div>

        @if($canCommentCase)
            <form method="post" action="{{ route('shelter.cases.updates.store', $case) }}">
                @csrf
                <label for="body">{{ __('cases::ui.show.blade.add_update') }}</label>
                <textarea id="body" name="body"></textarea>
                @if($canChooseVisibility)
                    <label for="visibility">{{ __('cases::ui.show.blade.visibility') }}</label>
                    <select id="visibility" name="visibility">
                        <option value="public">{{ __('cases::ui.show.blade.public') }}</option>
                        <option value="internal">{{ __('cases::ui.show.blade.internal_staff_only') }}</option>
                    </select>
                @endif
                <button type="submit">{{ __('cases::ui.show.blade.add_update') }}</button>
            </form>
        @elseif($case->status === 'closed')
            <p class="muted">{{ __('cases::ui.show.blade.reopen_this_case_before_adding_another_update') }}</p>
        @endif
    </div>
    <div class="panel">
        <h2>{{ __('cases::ui.show.blade.activity') }}</h2>
        @forelse($updates as $update)
            <div class="item-divider">
                <strong>{{ $update->user?->name ?? 'Former user' }}</strong>
                <span class="muted">{{ $update->created_at }}</span>
                @if($update->visibility === 'internal')<span class="status">internal</span>@endif
                <div>{{ $update->body }}</div>
            </div>
        @empty
            <p class="muted">{{ __('cases::ui.show.blade.no_updates_have_been_added') }}</p>
        @endforelse
    </div>
@endsection
