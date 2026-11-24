@if($canManageTwoFactor ?? false)
    <section class="panel" id="two-factor">
        <h2>{{ __('auth.two_factor.heading') }}</h2>
        <p class="muted">{{ __('auth.two_factor.intro') }}</p>

        @if($errors->has('two_factor'))
            <p class="error" role="alert">{{ $errors->first('two_factor') }}</p>
        @endif

        @if(count($plainRecoveryCodes) > 0)
            <div id="recovery-codes-once">
                <h3>{{ __('auth.two_factor.recovery_codes_heading') }}</h3>
                <p class="muted">{{ __('auth.two_factor.recovery_codes_once') }}</p>
                <ul class="recovery-codes">
                    @foreach($plainRecoveryCodes as $recoveryCode)
                        <li><code>{{ $recoveryCode }}</code></li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($twoFactorEnabled)
            <p>{{ __('auth.two_factor.enabled_status') }}</p>
            <form method="post" action="{{ route('two-factor.recovery.regenerate') }}">
                @csrf
                <p class="muted">{{ __('auth.two_factor.regenerate_note') }}</p>
                <div class="actions">
                    <button type="submit">{{ __('auth.two_factor.regenerate') }}</button>
                </div>
            </form>
            <form method="post" action="{{ route('two-factor.disable') }}" class="mt-2">
                @csrf
                @method('delete')
                <p class="muted">{{ __('auth.two_factor.disable_note') }}</p>
                <div class="actions">
                    <button type="submit">{{ __('auth.two_factor.disable') }}</button>
                </div>
            </form>
        @elseif($twoFactorPending)
            <p>{{ __('auth.two_factor.pending_status') }}</p>
            <div class="actions">
                <a href="{{ route('two-factor.setup') }}">{{ __('auth.two_factor.continue_setup') }}</a>
            </div>
            <form method="post" action="{{ route('two-factor.disable') }}">
                @csrf
                @method('delete')
                <div class="actions">
                    <button type="submit">{{ __('auth.two_factor.cancel_setup') }}</button>
                </div>
            </form>
        @else
            <form method="post" action="{{ route('two-factor.enable') }}">
                @csrf
                <div class="actions">
                    <button type="submit">{{ __('auth.two_factor.enable') }}</button>
                </div>
            </form>
        @endif
    </section>
@endif