@if($canChangeLocalUsername)
    <div class="panel" id="username">
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