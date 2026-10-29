@extends('layouts.app')

@section('title', 'APES CIC Case #'.$case->id)

@section('content')
    <div class="panel">
        <span class="service-label apes-cic">{{ __('cases::ui.index.blade.apes_cic') }}</span>
        <h1>Case #{{ $case->id }} - {{ $case->title }}</h1>
        <p class="muted">{{ $case->details }}</p>

        <dl class="ticket-meta">
            <div>
                <dt>{{ __('cases::ui.index.blade.owner') }}</dt>
                <dd>{{ $case->user?->name ?? '—' }}@if($case->user)<br><small class="muted">{{ $case->user->email }}</small>@endif</dd>
            </div>
            <div>
                <dt>{{ __('cases::ui.show.blade.assigned_staff') }}</dt>
                <dd>
                    @if($revealAssigneeIdentity)
                        {{ $case->assignedTo?->name ?? 'Unassigned' }}
                    @else
                        {{ $case->assigned_to ? 'Assigned' : 'Unassigned' }}
                    @endif
                </dd>
            </div>
            <div>
                <dt>{{ __('cases::ui.index.blade.category') }}</dt>
                <dd>{{ $categoryResolver->labelForCategory($case->sub_core_key, (string) $case->category) }}</dd>
            </div>
            @if($case->sub_category)
                <div>
                    <dt>{{ __('cases::ui.index.blade.subcategory') }}</dt>
                    <dd>{{ $categoryResolver->labelForSubcategory($case->sub_core_key, (string) $case->category, $case->sub_category) }}</dd>
                </div>
            @endif
            @if($case->affected_website_key)
                <div>
                    <dt>{{ __('cases::ui.show.blade.related_website') }}</dt>
                    <dd>{{ $categoryResolver->labelForWebsite($case->sub_core_key, $case->affected_website_key) }}</dd>
                </div>
            @endif
            <div>
                <dt>{{ __('cases::ui.index.blade.status') }}</dt>
                <dd><span class="status">{{ \App\Support\WorkflowLabels::status($case->status) }}</span></dd>
            </div>
            <div>
                <dt>{{ __('cases::ui.index.blade.priority') }}</dt>
                <dd>{{ \App\Support\WorkflowLabels::priority($case->priority) }} priority</dd>
            </div>
        </dl>

        @if($canUpdateCase || $canChangeAssignment)
            <form method="post" action="{{ route('apes-cic.cases.update', $case) }}" @if($canUpdateCase) data-case-update-form @endif>
                @csrf
                @method('patch')
                <div class="row">
                    @if($canUpdateCase)
                        <div>
                            <label for="category">{{ __('cases::ui.index.blade.category') }}</label>
                            <select id="category" name="category" data-category-parent>
                                @foreach($categoryGroups as $category)
                                    <option value="{{ $category['key'] }}" @selected($case->category === $category['key'])>
                                        {{ $category['label'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="sub_category">{{ __('cases::ui.index.blade.subcategory') }}</label>
                            <select id="sub_category" name="sub_category" data-category-child>
                                <option value="">{{ __('cases::ui.index.blade.select_subcategory') }}</option>
                            </select>
                        </div>
                        <div>
                            <label for="priority">{{ __('cases::ui.index.blade.priority') }}</label>
                            <select id="priority" name="priority">
                                @foreach($priorities as $priority)
                                    <option value="{{ $priority }}" @selected($case->priority === $priority)>{{ \App\Support\WorkflowLabels::priority($priority) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="status">{{ __('cases::ui.index.blade.status') }}</label>
                            <select id="status" name="status">
                                @foreach($statuses as $status)
                                    @continue((in_array($status, ['resolved', 'closed'], true) || in_array($case->status, ['resolved', 'closed'], true)) && ! $canCloseCase && $case->status !== $status)
                                    <option value="{{ $status }}" @selected($case->status === $status)>{{ \App\Support\WorkflowLabels::status($status) }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>
                @if($canUpdateCase)
                    <div data-website-field hidden>
                        <label for="affected_website_key">{{ __('cases::ui.index.blade.related_website_or_system') }}</label>
                        <select id="affected_website_key" name="affected_website_key">
                            <option value="">{{ __('cases::ui.index.blade.select_website') }}</option>
                            @foreach($websites as $website)
                                <option value="{{ $website['key'] }}" @selected($case->affected_website_key === $website['key'])>
                                    {{ $website['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @if($canChangeAssignment)
                    <div class="row">
                        <div>
                            <label for="user_id">{{ __('cases::ui.index.blade.owner') }}</label>
                            <select id="user_id" name="user_id">
                                @foreach($ownerCandidates as $ownerCandidate)
                                    <option value="{{ $ownerCandidate->id }}" @selected((int) $case->user_id === (int) $ownerCandidate->id)>
                                        {{ $ownerCandidate->name }} ({{ $ownerCandidate->email }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="assigned_to">{{ __('cases::ui.show.blade.assigned_staff') }}</label>
                            <select id="assigned_to" name="assigned_to">
                                <option value="">{{ __('cases::ui.show.blade.unassigned') }}</option>
                                @foreach($staffUsers as $staffUser)
                                    <option value="{{ $staffUser->id }}" @selected((int) $case->assigned_to === (int) $staffUser->id)>{{ $staffUser->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                @endif
                <button type="submit">{{ __('cases::ui.show.blade.save_case') }}</button>
            </form>
        @endif

        @if($canCommentCase)
            <form method="post" action="{{ route('apes-cic.cases.updates.store', $case) }}" @if($allowsAttachments) enctype="multipart/form-data" @endif>
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
                @if($allowsAttachments)
                    <p class="muted">{{ __('cases::ui.show.blade.to_add_evidence_files_use_save_case_after_uploading_on_') }}</p>
                @endif
                <button type="submit">{{ __('cases::ui.show.blade.add_update') }}</button>
            </form>
            @if($allowsAttachments)
                <form method="post" action="{{ route('apes-cic.cases.update', $case) }}" enctype="multipart/form-data">
                    @csrf
                    @method('patch')
                    <label for="case-screenshots">{{ __('cases::ui.show.blade.add_evidence_screenshots') }}</label>
                    <input id="case-screenshots" name="screenshots[]" type="file" accept="image/jpeg,image/png,image/webp" multiple>
                    <label for="case-screencast">{{ __('cases::ui.show.blade.add_evidence_screencast') }}</label>
                    <input id="case-screencast" name="screencast" type="file" accept="video/mp4,video/webm">
                    <button type="submit">{{ __('cases::ui.show.blade.upload_evidence') }}</button>
                </form>
            @endif
        @elseif($case->status === 'closed')
            <p class="muted">{{ __('cases::ui.show.blade.reopen_this_case_before_adding_another_update') }}</p>
        @endif

        @can('delete', $case)
            <form method="post" action="{{ route('apes-cic.cases.destroy', $case) }}" onsubmit="return confirm('Delete this case?')">
                @csrf
                @method('delete')
                <button type="submit" class="danger-btn">{{ __('cases::ui.show.blade.delete_case') }}</button>
            </form>
        @endcan
        <div class="actions"><a href="{{ route('apes-cic.cases.index') }}">{{ __('cases::ui.show.blade.back') }}</a></div>
    </div>

    @if($case->attachments->isNotEmpty())
        <div class="panel">
            <h2>{{ __('cases::ui.show.blade.attachments') }}</h2>
            <ul class="attachment-list">
                @foreach($case->attachments as $attachment)
                    <li>
                        <strong>{{ $attachment->kind }}</strong>
                        — {{ $attachment->original_name }}
                        <span class="muted">({{ number_format($attachment->size_bytes / 1024, 1) }} KB)</span>
                        <a href="{{ route('support.attachments.download', $attachment) }}">{{ __('cases::ui.index.blade.open') }}</a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

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

    @if($canUpdateCase)
        @include('partials.category-cascade-script', [
            'groups' => $categoryGroups,
            'oldParent' => old('category', $case->category),
            'oldChild' => old('sub_category', $case->sub_category),
        ])
    @endif
@endsection
