<div class="panel" id="sign-in-methods">
    <h2>{{ __('account.settings.sign_in_methods.heading') }}</h2>
    <p class="muted">{{ __('account.settings.sign_in_methods.intro') }}</p>

    @if($signInUsesDirectory)
        <p>{{ __('public.profile.edit.blade.this_account_uses_cloudron_directory_sign_in_change_you') }}</p>
    @else
        <ul>
            <li>{{ __('account.settings.sign_in_methods.password') }}</li>
            <li>
                @if($passkeyCount > 0)
                    {{ trans_choice('account.settings.sign_in_methods.passkeys', $passkeyCount, ['count' => $passkeyCount]) }}
                @else
                    {{ __('account.settings.sign_in_methods.passkeys_none') }}
                @endif
            </li>
            <li>
                @if($twoFactorEnabled)
                    {{ __('account.settings.sign_in_methods.two_factor_on') }}
                @else
                    {{ __('account.settings.sign_in_methods.two_factor_off') }}
                @endif
            </li>
        </ul>
        <p class="actions">
            <a href="{{ route('account.security') }}">{{ __('account.settings.sign_in_methods.manage_security') }}</a>
        </p>
    @endif
</div>