@extends('layouts.app')

@section('title', $caller->name.' | '.__('callers.title'))

@section('content')
    <div class="panel" data-caller="{{ $caller->id }}">
        <p class="eyebrow">{{ __('callers.title') }}</p>
        <h1>{{ $caller->name }}</h1>
        <p class="muted">{{ $caller->username }}</p>
        <a href="{{ route('callers.index') }}">{{ __('callers.back') }}</a>
    </div>

    <div class="panel" id="verify" data-caller-verification>
        <h2>{{ __('callers.verify_heading') }}</h2>
        @if($verified)
            <x-alert tone="ok">{{ __('callers.verified') }}</x-alert>
        @elseif($lockedForSeconds > 0)
            <x-alert tone="warning">{{ __('callers.locked', ['minutes' => (int) ceil($lockedForSeconds / 60)]) }}</x-alert>
        @elseif($positions === null)
            <x-alert tone="warning">{{ __('callers.no_pin') }}</x-alert>
        @else
            <p>{{ __('callers.ask_for_digits', ['first' => $positions[0], 'second' => $positions[1]]) }}</p>
            <form method="post" action="{{ route('callers.verify', $caller) }}" autocomplete="off">
                @csrf
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
@endsection
