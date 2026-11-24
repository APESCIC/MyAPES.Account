@php
    $callerHubReturn = $callerHubReturn ?? false;
@endphp
<div class="panel" id="call-logs" data-call-logs>
    <h2>{{ __('callers.call_logs_heading') }}</h2>
    <p class="muted">{{ __('callers.call_logs_intro') }}</p>
    <form method="post" action="{{ route('callers.call-logs.store', $caller) }}">
        @csrf
        @if($callerHubReturn)
            <input type="hidden" name="hub" value="1">
        @endif
        <fieldset @disabled(! $verified)>
            <legend class="visually-hidden">{{ __('callers.add_call_log') }}</legend>
            <label for="call-direction">{{ __('callers.direction') }}</label>
            <select id="call-direction" name="direction" required>
                <option value="inbound" @selected(old('direction', 'inbound') === 'inbound')>{{ __('callers.directions.inbound') }}</option>
                <option value="outbound" @selected(old('direction') === 'outbound')>{{ __('callers.directions.outbound') }}</option>
            </select>
            <label for="call-summary">{{ __('callers.summary') }}</label>
            <textarea id="call-summary" name="summary" maxlength="2000" required>{{ old('summary') }}</textarea>
            <div class="actions">
                <button type="submit">{{ __('callers.add_call_log') }}</button>
            </div>
        </fieldset>
    </form>

    @forelse($callLogs as $log)
        <article class="panel panel-flat" data-call-log="{{ $log->id }}">
            <p class="muted">
                {{ $log->called_at->format('d/m/Y H:i') }}
                · {{ $log->direction === 'outbound' ? __('callers.directions.outbound') : __('callers.directions.inbound') }}
                · {{ $log->staff?->name ?? __('callers.unknown_staff') }}
            </p>
            <p>{!! nl2br(e($log->summary)) !!}</p>
        </article>
    @empty
        <p class="muted">{{ __('callers.no_call_logs') }}</p>
    @endforelse
</div>
