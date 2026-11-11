<section class="panel" aria-labelledby="reply-mailbox-heading" data-settings-tab="reply-mailbox">
    <h2 id="reply-mailbox-heading">{{ __('admin.settings.reply_mailbox.heading') }}</h2>
    <p>{{ __('admin.settings.reply_mailbox.intro', ['address' => $address]) }}</p>
    <p class="muted" data-reply-mailbox-state>
        @if(! $repliesEnabled)
            {{ __('admin.settings.reply_mailbox.status_off') }}
        @elseif(! $pollingConfigured)
            {{ __('admin.settings.reply_mailbox.status_no_mailbox') }}
        @else
            {{ __('admin.settings.reply_mailbox.status_on') }}
        @endif
    </p>

    @php($canManage = auth()->user()?->can('admin.settings.manage'))
    @php($envBadge = '<span class="status" data-env-badge>'.e(__('admin.settings.reply_mailbox.env_badge')).'</span>')

    <form method="post" action="{{ route('admin.settings.reply-mailbox.update') }}" class="stacked-form" data-reply-mailbox-form autocomplete="off">
        @csrf
        @method('PUT')
        <fieldset @disabled(! $canManage)>
            <legend class="visually-hidden">{{ __('admin.settings.reply_mailbox.heading') }}</legend>

            <label class="inline-check">
                @unless($fields['enabled']['env'])<input type="hidden" name="enabled" value="0">@endunless
                <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $fields['enabled']['value'])) @disabled($fields['enabled']['env'])>
                <span>{{ __('admin.settings.reply_mailbox.fields.enabled') }}</span>
                @if($fields['enabled']['env']) {!! $envBadge !!} @endif
            </label>

            <label for="reply-mailbox-address">{{ __('admin.settings.reply_mailbox.fields.address') }} @if($fields['address']['env']) {!! $envBadge !!} @endif</label>
            <input id="reply-mailbox-address" name="address" type="email" maxlength="255" value="{{ old('address', $fields['address']['value']) }}" @disabled($fields['address']['env'])>

            <label for="reply-mailbox-host">{{ __('admin.settings.reply_mailbox.fields.host') }} @if($fields['host']['env']) {!! $envBadge !!} @endif</label>
            <input id="reply-mailbox-host" name="host" type="text" maxlength="255" value="{{ old('host', $fields['host']['value']) }}" placeholder="{{ __('admin.settings.reply_mailbox.host_placeholder') }}" @disabled($fields['host']['env'])>

            <label for="reply-mailbox-port">{{ __('admin.settings.reply_mailbox.fields.port') }} @if($fields['port']['env']) {!! $envBadge !!} @endif</label>
            <input id="reply-mailbox-port" name="port" type="number" min="1" max="65535" value="{{ old('port', $fields['port']['value']) }}" @disabled($fields['port']['env'])>

            <label for="reply-mailbox-encryption">{{ __('admin.settings.reply_mailbox.fields.encryption') }} @if($fields['encryption']['env']) {!! $envBadge !!} @endif</label>
            <select id="reply-mailbox-encryption" name="encryption" @disabled($fields['encryption']['env'])>
                @foreach($encryptions as $encryption => $label)
                    <option value="{{ $encryption }}" @selected(old('encryption', $fields['encryption']['value']) === $encryption)>{{ $label }}</option>
                @endforeach
            </select>

            <label class="inline-check">
                @unless($fields['validate_cert']['env'])<input type="hidden" name="validate_cert" value="0">@endunless
                <input type="checkbox" name="validate_cert" value="1" @checked(old('validate_cert', $fields['validate_cert']['value'])) @disabled($fields['validate_cert']['env'])>
                <span>{{ __('admin.settings.reply_mailbox.fields.validate_cert') }}</span>
                @if($fields['validate_cert']['env']) {!! $envBadge !!} @endif
            </label>

            <label for="reply-mailbox-username">{{ __('admin.settings.reply_mailbox.fields.username') }} @if($fields['username']['env']) {!! $envBadge !!} @endif</label>
            <input id="reply-mailbox-username" name="username" type="text" maxlength="255" autocomplete="off" value="{{ old('username', $fields['username']['value']) }}" @disabled($fields['username']['env'])>

            <label for="reply-mailbox-password">{{ __('admin.settings.reply_mailbox.fields.password') }} @if($fields['password']['env']) {!! $envBadge !!} @endif</label>
            <input id="reply-mailbox-password" name="password" type="password" maxlength="500" autocomplete="new-password" @disabled($fields['password']['env'])>
            <p class="muted" data-password-state>
                {{ $passwordSaved ? __('admin.settings.reply_mailbox.password_saved') : __('admin.settings.reply_mailbox.password_not_saved') }}
                {{ __('admin.settings.reply_mailbox.password_help') }}
            </p>

            <label for="reply-mailbox-folder">{{ __('admin.settings.reply_mailbox.fields.folder') }} @if($fields['folder']['env']) {!! $envBadge !!} @endif</label>
            <input id="reply-mailbox-folder" name="folder" type="text" maxlength="255" value="{{ old('folder', $fields['folder']['value']) }}" @disabled($fields['folder']['env'])>

            <label class="inline-check">
                @unless($fields['plus_addressing']['env'])<input type="hidden" name="plus_addressing" value="0">@endunless
                <input type="checkbox" name="plus_addressing" value="1" @checked(old('plus_addressing', $fields['plus_addressing']['value'])) @disabled($fields['plus_addressing']['env'])>
                <span>{{ __('admin.settings.reply_mailbox.fields.plus_addressing') }}</span>
                @if($fields['plus_addressing']['env']) {!! $envBadge !!} @endif
            </label>
            <p class="muted">{{ __('admin.settings.reply_mailbox.plus_addressing_help') }}</p>

            @if($canManage)
                <div class="actions">
                    <button type="submit">{{ __('admin.settings.reply_mailbox.save') }}</button>
                </div>
            @endif
        </fieldset>
    </form>
</section>
