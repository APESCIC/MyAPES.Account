@extends('layouts.app')

@section('title', $ticketService->serviceName.' Tickets')

@section('content')
    <div class="panel-stack">
        <div class="panel">
            <span class="service-label {{ $ticketService->presentationClass }}">{{ $ticketService->serviceName }}</span>
            <h1>{{ $ticketService->heading }}</h1>
            <p class="muted">{{ $ticketService->supportingCopy }}</p>
            <x-mascot-tip />
        </div>
        <div class="panel" id="list">
            <h2>{{ __('tickets::ui.index.blade.tickets') }}</h2>
            @if($tickets->isEmpty())
                <x-mascot-tip
                    variant="empty"
                    title="{{ __('tickets::ui.index.blade.no_tickets_are_available_to_you_yet') }}"
                    body="When a ticket is shared with you, or you create one, it will appear here."
                />
            @else
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('tickets::ui.index.blade.id') }}</th>
                            <th>{{ __('tickets::ui.index.blade.subject') }}</th>
                            <th>{{ __('tickets::ui.index.blade.area') }}</th>
                            <th>{{ __('tickets::ui.index.blade.status') }}</th>
                            <th>{{ __('tickets::ui.index.blade.priority') }}</th>
                            <th>{{ __('tickets::ui.index.blade.owner') }}</th>
                            <th>{{ __('tickets::ui.index.blade.assigned') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($tickets as $ticket)
                        <tr>
                            <td>#{{ $ticket->id }}</td>
                            <td>{{ $ticket->subject }}</td>
                            <td>
                                @if($usesHierarchicalCategories)
                                    {{ $categoryResolver->labelForArea($ticket->sub_core_key, $ticket->service_area) }}
                                    @if($ticket->sub_category)
                                        <br><small class="muted">{{ $categoryResolver->labelForSubcategory($ticket->sub_core_key, $ticket->service_area, $ticket->sub_category) }}</small>
                                    @endif
                                @else
                                    {{ $ticket->service_area }}
                                @endif
                            </td>
                            <td><span class="status">{{ \App\Support\WorkflowLabels::status($ticket->status) }}</span></td>
                            <td>{{ \App\Support\WorkflowLabels::priority($ticket->priority) }}</td>
                            <td>{{ $ticket->user?->name ?? '—' }}</td>
                            <td>
                                @if($revealAssigneeIdentity)
                                    {{ $ticket->assignedTo?->name ?? 'Unassigned' }}
                                @else
                                    {{ $ticket->assigned_to ? 'Assigned' : 'Unassigned' }}
                                @endif
                            </td>
                            <td><a href="{{ route($ticketService->routePrefix.'.show', $ticket) }}">{{ __('tickets::ui.index.blade.open') }}</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                {{ $tickets->links() }}
            @endif
        </div>
        @if($canCreateTicket)
            <div class="panel" id="create">
                <h2>{{ __('tickets::ui.index.blade.create_ticket') }}</h2>
                <form
                    method="post"
                    action="{{ route($ticketService->routePrefix.'.store') }}"
                    @if($usesHierarchicalCategories) enctype="multipart/form-data" @endif
                    data-ticket-create-form
                >
                @csrf
                <div class="row">
                    <div>
                        <label for="service_area">{{ __('tickets::ui.index.blade.service_area') }}</label>
                        <select id="service_area" name="service_area" data-category-parent required>
                            @if($usesHierarchicalCategories)
                                <option value="">{{ __('tickets::ui.index.blade.select_service_area') }}</option>
                                @foreach($serviceAreaGroups as $area)
                                    <option
                                        value="{{ $area['key'] }}"
                                        @selected(old('service_area') === $area['key'])
                                    >{{ $area['label'] }}</option>
                                @endforeach
                            @else
                                @foreach($serviceAreas as $serviceArea)
                                    <option value="{{ $serviceArea }}" @selected(old('service_area') === $serviceArea)>{{ $serviceArea }}</option>
                                @endforeach
                            @endif
                        </select>
                    </div>
                    @if($usesHierarchicalCategories)
                        <div>
                            <label for="sub_category">{{ __('tickets::ui.index.blade.subcategory') }}</label>
                            <select id="sub_category" name="sub_category" data-category-child required>
                                <option value="">{{ __('tickets::ui.index.blade.select_subcategory') }}</option>
                            </select>
                        </div>
                    @endif
                    <div>
                        <label for="priority">{{ __('tickets::ui.index.blade.priority') }}</label>
                        <select id="priority" name="priority">
                            @foreach($priorities as $priority)
                                <option value="{{ $priority }}" @selected(old('priority', 'medium') === $priority)>{{ \App\Support\WorkflowLabels::priority($priority) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                @if($usesHierarchicalCategories)
                    <div data-website-field hidden>
                        <label for="affected_website_key">
                            {{ __('tickets::ui.index.blade.affected_website') }}
                            <span class="required-mark" data-required-mark>{{ __('tickets::ui.index.blade.required') }}</span>
                        </label>
                        <select id="affected_website_key" name="affected_website_key">
                            <option value="">{{ __('tickets::ui.index.blade.select_website') }}</option>
                            @foreach($websites as $website)
                                <option value="{{ $website['key'] }}" @selected(old('affected_website_key') === $website['key'])>
                                    {{ $website['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <label for="subject">{{ __('tickets::ui.index.blade.subject') }}</label>
                <input id="subject" name="subject" value="{{ old('subject') }}" required>
                <label for="description">{{ __('tickets::ui.index.blade.description') }}</label>
                <textarea id="description" name="description" required>{{ old('description') }}</textarea>
                @if($usesHierarchicalCategories)
                    <div data-attachment-fields hidden>
                        <label for="screenshots">{{ __('tickets::ui.index.blade.screenshots_optional') }}</label>
                        <input id="screenshots" name="screenshots[]" type="file" accept="image/jpeg,image/png,image/webp" multiple>
                        <label for="screencast">{{ __('tickets::ui.index.blade.screencast_optional') }}</label>
                        <input id="screencast" name="screencast" type="file" accept="video/mp4,video/webm">
                    </div>
                @endif
                    <button type="submit">{{ __('tickets::ui.index.blade.create_ticket') }}</button>
                </form>
            </div>
        @endif
    </div>

    @if($usesHierarchicalCategories && $canCreateTicket)
        @include('partials.category-cascade-script', [
            'groups' => $serviceAreaGroups,
            'oldParent' => old('service_area'),
            'oldChild' => old('sub_category'),
        ])
    @endif
@endsection
