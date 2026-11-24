@if($canManagePasskeys ?? false)
    <section class="panel" id="passkeys" data-passkeys-manage>
        <h2>{{ __('auth.passkeys.heading') }}</h2>
        <p class="muted">{{ __('auth.passkeys.intro') }}</p>

        @if($errors->has('passkeys'))
            <p class="error" role="alert">{{ $errors->first('passkeys') }}</p>
        @endif

        @if($passkeys->isNotEmpty())
            <ul class="passkey-list">
                @foreach($passkeys as $passkey)
                    <li class="passkey-item">
                        <div>
                            <strong>{{ $passkey->name }}</strong>
                            <p class="muted">
                                {{ __('auth.passkeys.added_on', ['date' => optional($passkey->created_at)->format('d/m/Y')]) }}
                                @if($passkey->last_used_at)
                                    · {{ __('auth.passkeys.last_used', ['date' => $passkey->last_used_at->format('d/m/Y')]) }}
                                @endif
                            </p>
                        </div>
                        <form method="post" action="{{ route('passkey.destroy', $passkey) }}">
                            @csrf
                            @method('delete')
                            <button type="submit">{{ __('auth.passkeys.remove') }}</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="muted">{{ __('auth.passkeys.empty') }}</p>
        @endif

        <div class="passkey-register">
            <label for="passkey-name">{{ __('auth.passkeys.name_label') }}</label>
            <input id="passkey-name" type="text" name="passkey_name" maxlength="255" autocomplete="off" data-passkey-name value="{{ __('auth.passkeys.default_name') }}">
            <p class="error" role="alert" hidden data-passkey-error></p>
            <div class="actions">
                <button type="button" data-passkey-register>{{ __('auth.passkeys.add') }}</button>
            </div>
            <p class="muted">{{ __('auth.passkeys.add_note') }}</p>
        </div>
    </section>
@endif