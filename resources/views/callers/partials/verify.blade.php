@php
    $callerHubReturn = $callerHubReturn ?? false;
@endphp
<div class="panel" id="verify" data-caller-verification>
    <h2>{{ __('callers.verify_heading') }}</h2>
    @if($verified)
        <x-alert tone="ok">{{ __('callers.unlocked_until', ['time' => \Illuminate\Support\Carbon::createFromTimestamp($unlockedUntil, config('app.timezone'))->format('H:i')]) }}</x-alert>
        <form method="post" action="{{ route('callers.end-call', $caller) }}" data-end-call>
            @csrf
            @if($callerHubReturn)
                <input type="hidden" name="hub" value="1">
            @endif
            <div class="actions">
                <button type="submit">{{ __('callers.end_call') }}</button>
            </div>
        </form>
    @elseif($lockedForSeconds > 0)
        <x-alert tone="warning">{{ __('callers.locked', ['minutes' => (int) ceil($lockedForSeconds / 60)]) }}</x-alert>
    @elseif($positions === null)
        <x-alert tone="warning">{{ __('callers.no_pin') }}</x-alert>
    @else
        <p>{{ __('callers.ask_for_digits', ['first' => $positions[0], 'second' => $positions[1]]) }}</p>
        <form method="post" action="{{ route('callers.verify', $caller) }}" autocomplete="off">
            @csrf
            @if($callerHubReturn)
                <input type="hidden" name="hub" value="1">
            @endif
            <div class="row">
                @foreach($positions as $position)
                    <div>
                        <label for="pin-digit-{{ $position }}">{{ __('callers.digit_label', ['position' => $position]) }}</label>
                        <input id="pin-digit-{{ $position }}" type="password" name="pin_digits[{{ $position }}]" inputmode="numeric" pattern="[0-9]" maxlength="1" autocomplete="off" required>
                    </div>
                @endforeach
            </div>
            @error('pin_digits')
                <p class="sg-field__error">{{ $message }}</p>
            @enderror
            <div class="actions">
                <button type="submit">{{ __('callers.check') }}</button>
            </div>
        </form>
        <p class="muted">{{ __('callers.digits_hint') }}</p>
    @endif
</div>
