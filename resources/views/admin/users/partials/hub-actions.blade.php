@php
    $actionClient = $actionClient ?? ($managedUser ?? $client);
@endphp
<section class="panel" aria-labelledby="hub-actions-title" data-hub-actions>
    <h2 id="hub-actions-title">{{ __('admin.users.hub.quick_actions') }}</h2>
    @if($hubActions === [])
        <p class="muted">{{ __('admin.users.hub.no_modules_in_use') }}</p>
    @else
        <ul class="permission-readable-list">
            @foreach($hubActions as $action)
                <li>
                    <a href="{{ $action['url'] }}">{{ $action['label'] }}</a>
                    @if(! empty($action['open_url']))
                        · <a href="{{ $action['open_url'] }}">{{ __('admin.users.hub.records.open') }}</a>
                    @endif
                </li>
            @endforeach
        </ul>

        @foreach($hubActions as $action)
            @if(($action['plugin'] ?? '') === 'tickets')
                <form
                    id="hub-create-tickets-{{ $action['module'] }}"
                    method="post"
                    action="{{ route('clients.tickets.store', $actionClient) }}"
                    class="stack-spaced"
                >
                    @csrf
                    <input type="hidden" name="module" value="{{ $action['module'] }}">
                    <h3>{{ $action['label'] }}</h3>
                    @if($action['module'] === 'apes-cic')
                        <label for="hub-ticket-area-{{ $action['module'] }}">{{ __('tickets::ui.index.blade.service_area') }}</label>
                        <input id="hub-ticket-area-{{ $action['module'] }}" name="service_area" value="{{ old('service_area', 'operations_facilities') }}" required>
                        <label for="hub-ticket-sub-{{ $action['module'] }}">{{ __('tickets::ui.index.blade.subcategory') }}</label>
                        <input id="hub-ticket-sub-{{ $action['module'] }}" name="sub_category" value="{{ old('sub_category', 'premises') }}" required>
                    @else
                        <label for="hub-ticket-area-{{ $action['module'] }}">{{ __('tickets::ui.index.blade.service_area') }}</label>
                        <input id="hub-ticket-area-{{ $action['module'] }}" name="service_area" value="{{ old('service_area', 'rescue') }}">
                    @endif
                    <label for="hub-ticket-priority-{{ $action['module'] }}">{{ __('tickets::ui.index.blade.priority') }}</label>
                    <select id="hub-ticket-priority-{{ $action['module'] }}" name="priority">
                        @foreach(['low', 'medium', 'high', 'urgent'] as $priority)
                            <option value="{{ $priority }}" @selected(old('priority', 'medium') === $priority)>{{ \App\Support\WorkflowLabels::priority($priority) }}</option>
                        @endforeach
                    </select>
                    <label for="hub-ticket-subject-{{ $action['module'] }}">{{ __('tickets::ui.index.blade.subject') }}</label>
                    <input id="hub-ticket-subject-{{ $action['module'] }}" name="subject" value="{{ old('subject') }}" required>
                    <label for="hub-ticket-description-{{ $action['module'] }}">{{ __('tickets::ui.index.blade.description') }}</label>
                    <textarea id="hub-ticket-description-{{ $action['module'] }}" name="description" required>{{ old('description') }}</textarea>
                    <div class="actions"><button type="submit">{{ $action['label'] }}</button></div>
                </form>
            @elseif(($action['plugin'] ?? '') === 'cases')
                <form
                    id="hub-create-cases-{{ $action['module'] }}"
                    method="post"
                    action="{{ route('clients.cases.store', $actionClient) }}"
                    class="stack-spaced"
                >
                    @csrf
                    <input type="hidden" name="module" value="{{ $action['module'] }}">
                    <h3>{{ $action['label'] }}</h3>
                    <label for="hub-case-category-{{ $action['module'] }}">{{ __('cases::ui.index.blade.category') }}</label>
                    <input id="hub-case-category-{{ $action['module'] }}" name="category" value="{{ old('category', 'legal') }}" required>
                    <label for="hub-case-sub-{{ $action['module'] }}">{{ __('cases::ui.index.blade.subcategory') }}</label>
                    <input id="hub-case-sub-{{ $action['module'] }}" name="sub_category" value="{{ old('sub_category', 'contracts') }}" required>
                    <label for="hub-case-priority-{{ $action['module'] }}">{{ __('cases::ui.index.blade.priority') }}</label>
                    <select id="hub-case-priority-{{ $action['module'] }}" name="priority">
                        @foreach(['low', 'medium', 'high', 'urgent'] as $priority)
                            <option value="{{ $priority }}" @selected(old('priority', 'medium') === $priority)>{{ \App\Support\WorkflowLabels::priority($priority) }}</option>
                        @endforeach
                    </select>
                    <label for="hub-case-title-{{ $action['module'] }}">{{ __('cases::ui.index.blade.title') }}</label>
                    <input id="hub-case-title-{{ $action['module'] }}" name="title" value="{{ old('title') }}" required>
                    <label for="hub-case-details-{{ $action['module'] }}">{{ __('cases::ui.index.blade.details') }}</label>
                    <textarea id="hub-case-details-{{ $action['module'] }}" name="details">{{ old('details') }}</textarea>
                    <div class="actions"><button type="submit">{{ $action['label'] }}</button></div>
                </form>
            @endif
        @endforeach
    @endif

    <h3>{{ __('admin.users.hub.modules_in_use') }}</h3>
    @if($hubModulesInUse === [])
        <p class="muted">{{ __('admin.users.hub.no_modules_in_use') }}</p>
    @else
        <ul class="permission-readable-list">
            @foreach($hubModulesInUse as $module)
                <li><a href="{{ $module['url'] }}">{{ $module['label'] }}</a></li>
            @endforeach
        </ul>
    @endif
</section>
