<section class="panel" id="deletion-request" aria-labelledby="privacy-deletion-title">
    <h2 id="privacy-deletion-title">{{ __('account.settings.privacy.deletion.heading') }}</h2>
    <p class="muted">{{ __('account.settings.privacy.deletion.intro') }}</p>
    <form
        method="post"
        action="{{ route('account.privacy.deletion-request') }}"
        data-confirm="{{ __('account.settings.privacy.deletion.confirm') }}"
    >
        @csrf
        <label class="inline-check">
            <input type="checkbox" name="confirmed" value="1" required>
            {{ __('account.settings.privacy.deletion.confirm_label') }}
        </label>
        <div class="actions">
            <button type="submit">{{ __('account.settings.privacy.deletion.submit') }}</button>
        </div>
    </form>
</section>