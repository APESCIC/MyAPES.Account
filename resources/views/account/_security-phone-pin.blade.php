@if($canChangeLocalUsername ?? false)
    <section class="panel" id="phone-pin" data-phone-pin>
        <h2>{{ __('public.phone_pin.heading') }}</h2>
        <p class="muted">{{ __('public.phone_pin.intro') }}</p>
        <p data-phone-pin-status>
            @if($phonePin)
                {{ __('public.phone_pin.status_set', ['date' => $phonePin->set_at?->format('d/m/Y')]) }}
            @else
                {{ __('public.phone_pin.status_not_set') }}
            @endif
        </p>
        <form method="post" action="{{ route('profile.phone-pin.update') }}">
            @csrf
            @method('put')
            <label for="pin">{{ $phonePin ? __('public.phone_pin.new_pin') : __('public.phone_pin.pin') }}</label>
            <input id="pin" type="password" name="pin" inputmode="numeric" pattern="[0-9]*" minlength="4" maxlength="8" autocomplete="off" required>
            @error('pin')
                <p class="sg-field__error">{{ $message }}</p>
            @enderror
            <label for="pin_confirmation">{{ __('public.phone_pin.confirm_pin') }}</label>
            <input id="pin_confirmation" type="password" name="pin_confirmation" inputmode="numeric" pattern="[0-9]*" minlength="4" maxlength="8" autocomplete="off" required>
            <p class="muted">{{ __('public.phone_pin.hint') }}</p>
            <div class="actions">
                <button type="submit">{{ $phonePin ? __('public.phone_pin.change') : __('public.phone_pin.save') }}</button>
            </div>
        </form>
    </section>
@endif