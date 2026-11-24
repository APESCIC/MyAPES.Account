@if(session('temporary_password'))
    <section class="panel" aria-labelledby="temporary-password-title">
        <h2 id="temporary-password-title">{{ __('admin.users.one_time_temporary_password') }}</h2>
        <p>{{ __('admin.users.copy_this_password_now_and_share_it_with_the_account_holder_') }}</p>
        <label for="temporary-password">{{ __('admin.users.temporary_password') }}</label>
        <input
            id="temporary-password"
            class="temporary-password"
            type="text"
            readonly
            value="{{ session('temporary_password') }}"
            autocomplete="off"
            spellcheck="false"
        >
    </section>
@endif
