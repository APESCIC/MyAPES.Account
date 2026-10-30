@extends('layouts.app')

@section('title', 'Ticket #'.$ticket->id)

@section('content')
    <div class="panel">
        <span class="service-label {{ $ticketService->presentationClass }}">{{ $ticketService->serviceName }}</span>
        <h1>Ticket #{{ $ticket->id }} - {{ $ticket->subject }}</h1>
        <p class="muted">{{ $ticket->description }}</p>

        <dl class="ticket-meta">
            <div>
                <dt>{{ __('tickets::ui.index.blade.owner') }}</dt>
                <dd>{{ $ticket->user?->name ?? '—' }}@if($ticket->user)<br><small class="muted">{{ $ticket->user->email }}</small>@endif</dd>
            </div>
            <div>
                <dt>{{ __('tickets::ui.show.blade.assigned_staff') }}</dt>
                <dd>
                    @if($revealAssigneeIdentity)
                        {{ $ticket->assignedTo?->name ?? 'Unassigned' }}
                    @else
                        {{ $ticket->assigned_to ? 'Assigned' : 'Unassigned' }}
                    @endif
                </dd>
            </div>
            <div>
                <dt>{{ __('tickets::ui.index.blade.service_area') }}</dt>
                <dd>
                    @if($usesHierarchicalCategories)
                        {{ $categoryResolver->labelForArea($ticket->sub_core_key, $ticket->service_area) }}
                    @else
                        {{ \App\Support\WorkflowLabels::label('ticket_area', $ticket->service_area) }}
                    @endif
                </dd>
            </div>
            @if($usesHierarchicalCategories && $ticket->sub_category)
                <div>
                    <dt>{{ __('tickets::ui.index.blade.subcategory') }}</dt>
                    <dd>{{ $categoryResolver->labelForSubcategory($ticket->sub_core_key, $ticket->service_area, $ticket->sub_category) }}</dd>
                </div>
            @endif
            @if($usesHierarchicalCategories && $ticket->affected_website_key)
                <div>
                    <dt>{{ __('tickets::ui.index.blade.affected_website') }}</dt>
                    <dd>{{ $categoryResolver->labelForWebsite($ticket->sub_core_key, $ticket->affected_website_key) }}</dd>
                </div>
            @endif
            <div>
                <dt>{{ __('tickets::ui.index.blade.status') }}</dt>
                <dd><span class="status">{{ \App\Support\WorkflowLabels::status($ticket->status) }}</span></dd>
            </div>
            <div>
                <dt>{{ __('tickets::ui.index.blade.priority') }}</dt>
                <dd>{{ \App\Support\WorkflowLabels::priority($ticket->priority) }}</dd>
            </div>
        </dl>

        @if($canUpdateTicket || $canCommentTicket)
            <form id="ticket-workflow-form" method="post" action="{{ route($ticketService->routePrefix.'.update', $ticket) }}" @if($allowsAttachments) enctype="multipart/form-data" @endif>
                @csrf
                @method('put')
                @if($canUpdateTicket)
                    <div class="row">
                        <div>
                            <label for="status">{{ __('tickets::ui.index.blade.status') }}</label>
                            <select id="status" name="status">
                                @foreach(['open', 'in_progress', 'resolved', 'closed'] as $status)
                                    @continue(in_array($status, ['resolved', 'closed'], true) && ! $canCloseTicket && $ticket->status !== $status)
                                    <option value="{{ $status }}" @selected($ticket->status === $status)>{{ \App\Support\WorkflowLabels::status($status) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="priority">{{ __('tickets::ui.index.blade.priority') }}</label>
                            <select id="priority" name="priority">
                                @foreach($priorities as $priority)
                                    <option value="{{ $priority }}" @selected($ticket->priority === $priority)>{{ \App\Support\WorkflowLabels::priority($priority) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                @endif
                @if($canCommentTicket)
                    <label for="message">{{ __('tickets::ui.show.blade.add_message') }}</label>
                    <textarea id="message" name="message"></textarea>
                    @if($canChooseVisibility)
                        <label for="visibility">{{ __('tickets::ui.show.blade.visibility') }}</label>
                        <select id="visibility" name="visibility">
                            <option value="public">{{ __('tickets::ui.show.blade.public') }}</option>
                            <option value="internal">{{ __('tickets::ui.show.blade.internal_staff_only') }}</option>
                        </select>
                    @endif
                @endif
                @if($allowsAttachments && $canCommentTicket)
                    <label for="screenshots">{{ __('tickets::ui.show.blade.add_screenshots') }}</label>
                    <input id="screenshots" name="screenshots[]" type="file" accept="image/jpeg,image/png,image/webp" multiple>
                    <label for="screencast">{{ __('tickets::ui.show.blade.add_screencast') }}</label>
                    <input id="screencast" name="screencast" type="file" accept="video/mp4,video/webm">
                @endif
                <div class="actions">
                    <button type="submit">{{ $canUpdateTicket ? 'Save ticket' : 'Add message' }}</button>
                </div>
            </form>
        @endif
        @if($canChangeAssignment)
            <form id="ticket-owner-search" method="get" action="{{ route($ticketService->routePrefix.'.show', $ticket) }}#ticket-assignment-form"></form>
            <form id="ticket-assignment-form" method="post" action="{{ route($ticketService->routePrefix.'.update', $ticket) }}">
                @csrf
                @method('put')
                <div class="row">
                    <div data-owner-picker data-owner-endpoint="{{ route($ticketService->routePrefix.'.owner-candidates', $ticket) }}" data-owner-empty="{{ __('tickets::ui.show.blade.no_owner_matches') }}">
                        <label for="owner_q">{{ __('tickets::ui.show.blade.find_owner') }}</label>
                        <div class="actions">
                            <input
                                id="owner_q"
                                type="search"
                                name="owner_q"
                                form="ticket-owner-search"
                                value="{{ $ownerSearch }}"
                                minlength="{{ \Plugins\Tickets\TicketOwnerCandidates::MIN_TERM_LENGTH }}"
                                autocomplete="off"
                                aria-describedby="owner_q_hint"
                                data-owner-search
                            >
                            <button type="submit" form="ticket-owner-search" class="button-secondary">{{ __('tickets::ui.show.blade.search') }}</button>
                        </div>
                        <p id="owner_q_hint" class="muted">{{ __('tickets::ui.show.blade.find_owner_hint') }}</p>
                        <label for="user_id">{{ __('tickets::ui.index.blade.owner') }}</label>
                        <select id="user_id" name="user_id" data-owner-results>
                            <option value="{{ $ticket->user_id }}" selected data-owner-current>
                                {{ __('tickets::ui.show.blade.current_owner', ['owner' => $ticket->user?->name ?? '—']) }}
                            </option>
                            @foreach($ownerMatches as $ownerMatch)
                                <option value="{{ $ownerMatch->id }}">{{ $ownerMatch->name }} ({{ $ownerMatch->email }})</option>
                            @endforeach
                        </select>
                        <p class="muted" role="status" data-owner-status>
                            @if($ownerSearchHadNoMatches)
                                {{ __('tickets::ui.show.blade.no_owner_matches') }}
                            @endif
                        </p>
                    </div>
                    <div>
                        <label for="assigned_to">{{ __('tickets::ui.show.blade.assigned_staff') }}</label>
                        <select id="assigned_to" name="assigned_to">
                            <option value="">{{ __('tickets::ui.show.blade.unassigned') }}</option>
                            @foreach($staffUsers as $staffUser)
                                <option value="{{ $staffUser->id }}" @selected((int)$ticket->assigned_to === (int)$staffUser->id)>{{ $staffUser->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <button type="submit">{{ __('tickets::ui.show.blade.update_ownership') }}</button>
            </form>
        @endif
        <div class="actions">
            <a href="{{ route($ticketService->routePrefix.'.index') }}">{{ __('tickets::ui.show.blade.back') }}</a>
        </div>
        @if($ticketService->supportsDelete && auth()->user()->can('delete', $ticket))
            <form method="post" action="{{ route($ticketService->routePrefix.'.destroy', $ticket) }}" onsubmit="return confirm('Delete this ticket?')">
                @csrf
                @method('delete')
                <button type="submit" class="danger-btn">{{ __('tickets::ui.show.blade.delete_ticket') }}</button>
            </form>
        @endif
    </div>

    @if($ticket->attachments->isNotEmpty())
        <div class="panel">
            <h2>{{ __('tickets::ui.show.blade.attachments') }}</h2>
            <ul class="attachment-list">
                @foreach($ticket->attachments as $attachment)
                    <li>
                        <strong>{{ $attachment->kind }}</strong>
                        — {{ $attachment->original_name }}
                        <span class="muted">({{ number_format($attachment->size_bytes / 1024, 1) }} KB)</span>
                        <a href="{{ route('support.attachments.download', $attachment) }}">{{ __('tickets::ui.index.blade.open') }}</a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="panel" data-ticket-activity>
        <h2>{{ __('tickets::ui.show.blade.activity') }}</h2>
        @if($canCommentTicket && ! $canUpdateTicket)
            <p class="muted" data-ticket-activity-hint>{{ __('tickets::ui.show.blade.you_can_add_an_update_comment_to_this_ticket') }}</p>
        @endif
        <div class="item-divider" data-ticket-activity-opener>
            <strong>{{ ($activityOpenerUser ?? $ticket->user)?->name ?? '—' }}</strong>
            <span class="muted">{{ $ticket->created_at }}</span>
            <div>{{ $ticket->description }}</div>
        </div>
        @foreach(($messages ?? $ticket->messages) as $message)
            @continue((int) $message->id === (int) ($hiddenCreationMessageId ?? 0))
            <div class="item-divider">
                <strong>{{ $message->user->name }}</strong>
                <span class="muted">{{ $message->created_at }}</span>
                @if($message->is_staff_note)
                    <span class="status">staff</span>
                @endif
                <div>{{ $message->message }}</div>
            </div>
        @endforeach
    </div>
@endsection
