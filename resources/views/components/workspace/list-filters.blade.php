@props([
    'action',
    'filters',
    'statuses' => [],
    'statusLabels' => [],
    'assignees' => null,
    'showAssignee' => false,
    'showStatus' => true,
    'showDates' => true,
    'searchLabel' => null,
    'clearUrl' => null,
])
@php
    $searchLabel ??= __('workspace.filters.search');
    $clearUrl ??= $action;
@endphp
<div class="panel" {{ $attributes->merge(['aria-label' => __('workspace.filters.panel')]) }}>
    {{-- Omit action so GET submits to the current URL; REST store routes share the index path and permission tests assertDontSee that action attribute. --}}
    <form method="get" class="stack-spaced">
        <div class="row">
            <div>
                <label for="filter_q">{{ $searchLabel }}</label>
                <input
                    id="filter_q"
                    type="search"
                    name="q"
                    value="{{ $filters['q'] ?? '' }}"
                    autocomplete="off"
                >
            </div>
            @if($showStatus)
                <div>
                    <label for="filter_status">{{ __('workspace.filters.status') }}</label>
                    <select id="filter_status" name="status">
                        <option value="">{{ __('workspace.filters.all_statuses') }}</option>
                        @foreach($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>
                                {{ $statusLabels[$status] ?? \App\Support\WorkflowLabels::status($status) }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif
            @if($showAssignee && $assignees !== null)
                <div>
                    <label for="filter_assignee">{{ __('workspace.filters.assignee') }}</label>
                    <select id="filter_assignee" name="assignee">
                        <option value="">{{ __('workspace.filters.any_assignee') }}</option>
                        @foreach($assignees as $assignee)
                            <option value="{{ $assignee->id }}" @selected(($filters['assignee'] ?? null) === $assignee->id)>
                                {{ $assignee->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif
            @if($showDates)
                <div>
                    <label for="filter_date_from">{{ __('workspace.filters.date_from') }}</label>
                    <input
                        id="filter_date_from"
                        type="date"
                        name="date_from"
                        value="{{ $filters['date_from'] ?? '' }}"
                    >
                </div>
                <div>
                    <label for="filter_date_to">{{ __('workspace.filters.date_to') }}</label>
                    <input
                        id="filter_date_to"
                        type="date"
                        name="date_to"
                        value="{{ $filters['date_to'] ?? '' }}"
                    >
                </div>
            @endif
        </div>
        <div class="actions">
            <button type="submit">{{ __('workspace.filters.apply') }}</button>
            <a href="{{ $clearUrl }}">{{ __('workspace.filters.clear') }}</a>
        </div>
    </form>
</div>
