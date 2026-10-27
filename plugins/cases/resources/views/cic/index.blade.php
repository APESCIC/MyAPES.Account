@extends('layouts.app')

@section('title', 'APES CIC Cases')

@section('content')
    <div class="panel-stack">
        <div class="panel">
            <span class="service-label apes-cic">{{ __('cases::ui.index.blade.apes_cic') }}</span>
            <h1>{{ __('cases::ui.index.blade.cases') }}</h1>
            <p class="muted">{{ __('cases::ui.index.blade.formal_casework_including_data_access_privacy_requests_') }}</p>
            <x-mascot-tip />
        </div>
        <div class="panel" id="list">
            <h2>{{ __('cases::ui.index.blade.your_available_cases') }}</h2>
            @if($cases->isEmpty())
                <x-mascot-tip
                    variant="empty"
                    title="{{ __('cases::ui.index.blade.no_cases_are_available_to_you_yet') }}"
                    body="When a case is shared with you, or you open one, it will appear here."
                />
            @else
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('cases::ui.index.blade.id') }}</th>
                            <th>{{ __('cases::ui.index.blade.title') }}</th>
                            <th>{{ __('cases::ui.index.blade.category') }}</th>
                            <th>{{ __('cases::ui.index.blade.status') }}</th>
                            <th>{{ __('cases::ui.index.blade.priority') }}</th>
                            <th>{{ __('cases::ui.index.blade.owner') }}</th>
                            <th>{{ __('cases::ui.index.blade.assigned') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($cases as $case)
                        <tr>
                            <td>#{{ $case->id }}</td>
                            <td>{{ $case->title }}</td>
                            <td>
                                {{ $categoryResolver->labelForCategory($case->sub_core_key, (string) $case->category) }}
                                @if($case->sub_category)
                                    <br><small class="muted">{{ $categoryResolver->labelForSubcategory($case->sub_core_key, (string) $case->category, $case->sub_category) }}</small>
                                @endif
                            </td>
                            <td><span class="status">{{ $case->status }}</span></td>
                            <td>{{ $case->priority }}</td>
                            <td>{{ $case->user?->name ?? '—' }}</td>
                            <td>
                                @if($revealAssigneeIdentity)
                                    {{ $case->assignedTo?->name ?? 'Unassigned' }}
                                @else
                                    {{ $case->assigned_to ? 'Assigned' : 'Unassigned' }}
                                @endif
                            </td>
                            <td><a href="{{ route('apes-cic.cases.show', $case) }}">{{ __('cases::ui.index.blade.open') }}</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                {{ $cases->links() }}
            @endif
        </div>
        @if($canCreateCase)
            <div class="panel" id="create">
                <h2>{{ __('cases::ui.index.blade.open_a_case') }}</h2>
                <form method="post" action="{{ route('apes-cic.cases.store') }}" enctype="multipart/form-data" data-case-create-form>
                @csrf
                <div class="row">
                    <div>
                        <label for="category">{{ __('cases::ui.index.blade.category') }}</label>
                        <select id="category" name="category" data-category-parent required>
                            @foreach($categoryGroups as $category)
                                <option value="{{ $category['key'] }}" @selected(old('category') === $category['key'])>
                                    {{ $category['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="sub_category">{{ __('cases::ui.index.blade.subcategory') }}</label>
                        <select id="sub_category" name="sub_category" data-category-child required>
                            <option value="">{{ __('cases::ui.index.blade.select_subcategory') }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="priority">{{ __('cases::ui.index.blade.priority') }}</label>
                        <select id="priority" name="priority">
                            @foreach($priorities as $priority)
                                <option value="{{ $priority }}" @selected(old('priority', 'medium') === $priority)>{{ $priority }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div data-website-field hidden>
                    <label for="affected_website_key">{{ __('cases::ui.index.blade.related_website_or_system') }}</label>
                    <select id="affected_website_key" name="affected_website_key">
                        <option value="">{{ __('cases::ui.index.blade.select_website') }}</option>
                        @foreach($websites as $website)
                            <option value="{{ $website['key'] }}" @selected(old('affected_website_key') === $website['key'])>
                                {{ $website['label'] }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <label for="title">{{ __('cases::ui.index.blade.title') }}</label>
                <input id="title" name="title" value="{{ old('title') }}" required>
                <label for="details">{{ __('cases::ui.index.blade.details') }}</label>
                <textarea id="details" name="details">{{ old('details') }}</textarea>
                <div data-attachment-fields hidden>
                    <label for="screenshots">{{ __('cases::ui.index.blade.evidence_screenshots_optional') }}</label>
                    <input id="screenshots" name="screenshots[]" type="file" accept="image/jpeg,image/png,image/webp" multiple>
                    <label for="screencast">{{ __('cases::ui.index.blade.evidence_screencast_optional') }}</label>
                    <input id="screencast" name="screencast" type="file" accept="video/mp4,video/webm">
                </div>
                    <button type="submit">{{ __('cases::ui.index.blade.open_case') }}</button>
                </form>
            </div>
        @endif
    </div>

    @if($canCreateCase)
        @include('partials.category-cascade-script', [
            'groups' => $categoryGroups,
            'oldParent' => old('category'),
            'oldChild' => old('sub_category'),
        ])
    @endif
@endsection
