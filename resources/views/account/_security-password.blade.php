@if($canChangeLocalPassword)
    <section class="panel" id="password">
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
    </section>
@else
    <section class="panel" id="password">
        <h2>{{ __('public.profile.edit.blade.password') }}</h2>
        <p class="muted">{{ __('public.profile.edit.blade.this_account_uses_cloudron_directory_sign_in_change_you') }}</p>
    </section>
@endif