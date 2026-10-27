@extends('layouts.app')

@section('title', 'Profile | MyAPES Core')

@section('content')
    <div class="panel-stack">
        <div class="panel">
            <h1>{{ __('public.profile.edit.blade.your_profile') }}</h1>
            <p class="muted">{{ __('public.profile.edit.blade.core_account_details_used_across_all_apes_services') }}</p>
            <form method="post" action="{{ route('profile.update') }}" enctype="multipart/form-data">
                @csrf
                @method('put')
                <div class="row">
                    <div>
                        <label for="preferred_name">{{ __('public.profile.edit.blade.preferred_name') }}</label>
                        <input id="preferred_name" name="preferred_name" value="{{ old('preferred_name', $profile?->preferred_name) }}">
                    </div>
                    <div>
                        <label for="phone">{{ __('public.profile.edit.blade.phone') }}</label>
                        <input id="phone" name="phone" value="{{ old('phone', $profile?->phone) }}">
                    </div>
                    <div>
                        <label for="organization">{{ __('public.profile.edit.blade.organisation') }}</label>
                        <input id="organization" name="organization" value="{{ old('organization', $profile?->organization) }}">
                    </div>
                </div>
                <label for="support_needs">{{ __('public.profile.edit.blade.support_needs_or_access_notes') }}</label>
                <textarea id="support_needs" name="support_needs">{{ old('support_needs', $profile?->support_needs) }}</textarea>
                @include('profile._account-fields')
                <label for="avatar">{{ __('public.profile.edit.blade.avatar_photo') }}</label>
                <input id="avatar" type="file" name="avatar" accept="image/*">
                <div class="actions">
                    <button type="submit">{{ __('public.profile.edit.blade.save_profile') }}</button>
                </div>
            </form>
            <x-locale-switcher />
        </div>

        <div class="panel" id="account-email">
            <h2>{{ __('public.profile.edit.blade.account_email') }}</h2>
            @if($canChangeLocalEmail)
                <p class="muted">{{ __('auth.email_change.intro') }}</p>
            @else
                <p class="muted">{{ __('auth.email_change.directory_owned') }}</p>
            @endif
            <dl class="admin-definition-list">
                <div>
                    <dt>{{ __('public.profile.edit.blade.email') }}</dt>
                    <dd>{{ auth()->user()->email }}</dd>
                </div>
                <div>
                    <dt>{{ __('auth.common.username') }}</dt>
                    <dd>{{ auth()->user()->username }}</dd>
                </div>
            </dl>

            @if($canChangeLocalEmail)
                @if($pendingEmailChange)
                    <div id="pending-email-change">
                        <p>{{ __('auth.email_change.pending', ['email' => $pendingEmailChange->new_email]) }}</p>
                        <p class="muted">{{ __('auth.email_change.pending_help') }}</p>
                        <form method="post" action="{{ route('profile.email.cancel') }}">
                            @csrf
                            @method('delete')
                            <div class="actions">
                                <button type="submit">{{ __('auth.email_change.cancel') }}</button>
                            </div>
                        </form>
                    </div>
                @else
                    <div id="change-email">
                        <h3>{{ __('auth.email_change.heading') }}</h3>
                        <form method="post" action="{{ route('profile.email.change') }}">
                            @csrf
                            <label for="email">{{ __('auth.email_change.new_email') }}</label>
                            <input
                                id="email"
                                type="email"
                                name="email"
                                value="{{ old('email') }}"
                                autocomplete="email"
                                required
                            >
                            <p class="muted">{{ __('auth.email_change.step_up_note') }}</p>
                            <div class="actions">
                                <button type="submit">{{ __('auth.email_change.submit') }}</button>
                            </div>
                        </form>
                    </div>
                @endif
            @endif
        </div>

        @if($canChangeLocalUsername)
            <div class="panel" id="change-username">
                <h2>{{ __('auth.username_change.heading') }}</h2>
                <p class="muted">{{ __('auth.username_change.intro') }}</p>
                <form method="post" action="{{ route('profile.username.update') }}">
                    @csrf
                    @method('put')
                    <label for="username">{{ __('auth.common.username') }}</label>
                    <input
                        id="username"
                        type="text"
                        name="username"
                        value="{{ old('username', auth()->user()->username) }}"
                        autocomplete="username"
                        minlength="3"
                        maxlength="30"
                        pattern="[A-Za-z0-9](?:[A-Za-z0-9._-]{1,28}[A-Za-z0-9])"
                        required
                    >
                    <p class="muted">{{ __('auth.register.username_guidance') }}</p>
                    <div class="actions">
                        <button type="submit">{{ __('auth.username_change.submit') }}</button>
                    </div>
                </form>
            </div>
        @endif

        @if($canChangeLocalPassword)
            <div class="panel" id="change-password">
                <h2>{{ __('public.profile.edit.blade.change_password') }}</h2>
                <p class="muted">{{ __('public.profile.edit.blade.enter_your_current_password_then_choose_a_new_one_for_t') }}</p>
                <form method="post" action="{{ route('profile.password.update') }}">
                    @csrf
                    @method('put')
                    <label for="current_password">{{ __('public.profile.edit.blade.current_password') }}</label>
                    <input id="current_password" type="password" name="current_password" autocomplete="current-password" required>

                    <label for="password">{{ __('public.profile.edit.blade.new_password') }}</label>
                    <input id="password" type="password" name="password" autocomplete="new-password" required>

                    <label for="password_confirmation">{{ __('public.profile.edit.blade.confirm_new_password') }}</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>

                    <div class="actions">
                        <button type="submit">{{ __('public.profile.edit.blade.update_password') }}</button>
                    </div>
                </form>
            </div>
        @else
            <div class="panel">
                <h2>{{ __('public.profile.edit.blade.password') }}</h2>
                <p class="muted">{{ __('public.profile.edit.blade.this_account_uses_cloudron_directory_sign_in_change_you') }}</p>
            </div>
        @endif

        @if($canManageTwoFactor)
            <div class="panel" id="two-factor">
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
            </div>
        @endif

        @if($canManagePasskeys)
            <div class="panel" id="passkeys" data-passkeys-manage>
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
            </div>
        @endif
    </div>
@endsection
