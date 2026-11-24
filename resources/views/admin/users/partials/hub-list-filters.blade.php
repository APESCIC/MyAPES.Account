@php
    $filters = $hubListFilters ?? ['module' => '', 'from' => null, 'to' => null];
@endphp
<form method="get" action="{{ url()->current() }}" class="hub-list-filters" data-hub-list-filters>
    <input type="hidden" name="tab" value="{{ $hubTab }}">
    <label for="hub-filter-module">{{ __('admin.users.hub.filters.module') }}</label>
    <select id="hub-filter-module" name="module">
        <option value="">{{ __('admin.users.hub.filters.all_modules') }}</option>
        @foreach (['apes-cic' => 'APES CIC', 'shelter-rescue' => 'APES Shelter and Rescue', 'pet-care-clinic' => 'APES Pet Care Clinic'] as $key => $label)
            <option value="{{ $key }}" @selected(($filters['module'] ?? '') === $key)>{{ $label }}</option>
        @endforeach
    </select>
    <label for="hub-filter-from">{{ __('admin.users.hub.filters.from') }}</label>
    <input id="hub-filter-from" type="date" name="from" value="{{ optional($filters['from'])->toDateString() }}">
    <label for="hub-filter-to">{{ __('admin.users.hub.filters.to') }}</label>
    <input id="hub-filter-to" type="date" name="to" value="{{ optional($filters['to'])->toDateString() }}">
    <div class="actions">
        <button type="submit">{{ __('admin.users.hub.filters.apply') }}</button>
    </div>
</form>
