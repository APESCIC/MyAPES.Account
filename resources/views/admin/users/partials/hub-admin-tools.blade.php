@can('admin.users.manage')
    @if($canManageTarget ?? false)
        <section class="panel" aria-labelledby="hub-admin-tools-title" data-hub-admin-tools>
            <h2 id="hub-admin-tools-title">{{ __('admin.users.hub.admin_tools.heading') }}</h2>

            <h3>{{ __('admin.users.hub.admin_tools.contact_preferences') }}</h3>
            <form method="post" action="{{ route('admin.users.contact-preferences.update', $managedUser) }}">
                @csrf
                @method('put')
                <fieldset>
                    <legend>{{ __('admin.users.hub.admin_tools.contact_preferences') }}</legend>
                    @foreach (['calls' => 'Calls', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'email' => 'Email'] as $channel => $label)
                        <label class="inline-check">
                            <input type="checkbox" name="contact_{{ $channel }}" value="1" @checked(old('contact_'.$channel, $preference?->{$channel} ?? false))>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                    <input type="hidden" name="contact_preferences_confirmed" value="1">
                </fieldset>
                <div class="actions">
                    <button type="submit">{{ __('admin.users.hub.admin_tools.save_contact_preferences') }}</button>
                </div>
            </form>

            @if($canResendVerification)
                <h3 id="resend-verification-title">{{ __('admin.emails.resend_heading') }}</h3>
                <p class="muted">{{ __('admin.emails.resend_help') }}</p>
                <form method="post" action="{{ route('admin.users.resend-verification', $managedUser) }}">
                    @csrf
                    <div class="actions">
                        <button type="submit">{{ __('admin.emails.resend_button') }}</button>
                    </div>
                </form>
            @endif

            <h3>{{ __('admin.users.hub.admin_tools.two_factor') }}</h3>
            @if($twoFactorEnabled)
                <p class="muted">{{ __('admin.users.hub.admin_tools.two_factor_help') }}</p>
                <form method="post" action="{{ route('admin.users.two-factor.reset', $managedUser) }}">
                    @csrf
                    <label class="inline-check">
                        <input type="checkbox" name="confirm_two_factor_reset" value="1" required>
                        <span>{{ __('admin.users.hub.admin_tools.two_factor_confirm') }}</span>
                    </label>
                    <div class="actions">
                        <button class="danger-btn" type="submit">{{ __('admin.users.hub.admin_tools.two_factor_reset') }}</button>
                    </div>
                </form>
            @else
                <p class="muted">{{ __('admin.users.hub.admin_tools.two_factor_not_enabled') }}</p>
            @endif
        </section>
    @endif
@endcan
