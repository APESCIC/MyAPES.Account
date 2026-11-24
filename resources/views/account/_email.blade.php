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