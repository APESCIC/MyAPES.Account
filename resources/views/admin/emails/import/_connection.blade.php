<section class="panel" aria-labelledby="mailbox-connection-heading" data-mailbox-connection>
    <h2 id="mailbox-connection-heading">{{ __('admin.emails.import.connection_heading') }}</h2>

    @php($canManage = auth()->user()?->can('admin.settings.manage'))
    @php($envBadge = '<span class="status" data-env-badge>'.e(__('admin.emails.import.env_badge')).'</span>')

    <form method="post" action="{{ route('admin.emails.import.update') }}" class="stacked-form" data-reply-mailbox-form autocomplete="off">
        @csrf
        @method('PUT')
        <fieldset @disabled(! $canManage)>
            <legend class="visually-hidden">{{ __('admin.emails.import.connection_heading') }}</legend>

            <label class="inline-check">
                @unless($fields['enabled']['env'])<input type="hidden" name="enabled" value="0">@endunless
                <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $fields['enabled']['value'])) @disabled($fields['enabled']['env'])>
                <span>{{ __('admin.emails.import.fields.enabled') }}</span>
                @if($fields['enabled']['env']) {!! $envBadge !!} @endif
            </label>

            <label for="reply-mailbox-address">{{ __('admin.emails.import.fields.address') }} @if($fields['address']['env']) {!! $envBadge !!} @endif</label>
            <input id="reply-mailbox-address" name="address" type="email" maxlength="255" value="{{ old('address', $fields['address']['value']) }}" @disabled($fields['address']['env'])>

            <label for="reply-mailbox-host">{{ __('admin.emails.import.fields.host') }} @if($fields['host']['env']) {!! $envBadge !!} @endif</label>
            <input id="reply-mailbox-host" name="host" type="text" maxlength="255" value="{{ old('host', $fields['host']['value']) }}" placeholder="{{ __('admin.emails.import.host_placeholder') }}" @disabled($fields['host']['env'])>

            <label for="reply-mailbox-port">{{ __('admin.emails.import.fields.port') }} @if($fields['port']['env']) {!! $envBadge !!} @endif</label>
            <input id="reply-mailbox-port" name="port" type="number" min="1" max="65535" value="{{ old('port', $fields['port']['value']) }}" @disabled($fields['port']['env'])>

            <label for="reply-mailbox-encryption">{{ __('admin.emails.import.fields.encryption') }} @if($fields['encryption']['env']) {!! $envBadge !!} @endif</label>
            <select id="reply-mailbox-encryption" name="encryption" aria-describedby="reply-mailbox-encryption-help" @disabled($fields['encryption']['env'])>
                @foreach($encryptions as $encryption => $label)
                    <option value="{{ $encryption }}" @selected(old('encryption', $fields['encryption']['value']) === $encryption)>{{ $label }}</option>
                @endforeach
            </select>
            <p class="muted" id="reply-mailbox-encryption-help">{{ __('admin.emails.import.encryption_help') }}</p>

            <label class="inline-check">
                @unless($fields['validate_cert']['env'])<input type="hidden" name="validate_cert" value="0">@endunless
                <input type="checkbox" name="validate_cert" value="1" @checked(old('validate_cert', $fields['validate_cert']['value'])) @disabled($fields['validate_cert']['env'])>
                <span>{{ __('admin.emails.import.fields.validate_cert') }}</span>
                @if($fields['validate_cert']['env']) {!! $envBadge !!} @endif
            </label>

            <label for="reply-mailbox-username">{{ __('admin.emails.import.fields.username') }} @if($fields['username']['env']) {!! $envBadge !!} @endif</label>
            <input id="reply-mailbox-username" name="username" type="text" maxlength="255" autocomplete="off" value="{{ old('username', $fields['username']['value']) }}" aria-describedby="reply-mailbox-username-help" @disabled($fields['username']['env'])>
            <p class="muted" id="reply-mailbox-username-help">{{ __('admin.emails.import.username_help') }}</p>

            <label for="reply-mailbox-password">{{ __('admin.emails.import.fields.password') }} @if($fields['password']['env']) {!! $envBadge !!} @endif</label>
            <input id="reply-mailbox-password" name="password" type="password" maxlength="500" autocomplete="new-password" @disabled($fields['password']['env'])>
            <p class="muted" data-password-state>
                {{ $passwordSaved ? __('admin.emails.import.password_saved') : __('admin.emails.import.password_not_saved') }}
                {{ __('admin.emails.import.password_help') }}
            </p>

            <label for="reply-mailbox-folder">{{ __('admin.emails.import.fields.folder') }} @if($fields['folder']['env']) {!! $envBadge !!} @endif</label>
            <input id="reply-mailbox-folder" name="folder" type="text" maxlength="255" value="{{ old('folder', $fields['folder']['value']) }}" @disabled($fields['folder']['env'])>

            <label class="inline-check">
                @unless($fields['plus_addressing']['env'])<input type="hidden" name="plus_addressing" value="0">@endunless
                <input type="checkbox" name="plus_addressing" value="1" @checked(old('plus_addressing', $fields['plus_addressing']['value'])) @disabled($fields['plus_addressing']['env'])>
                <span>{{ __('admin.emails.import.fields.plus_addressing') }}</span>
                @if($fields['plus_addressing']['env']) {!! $envBadge !!} @endif
            </label>
            <p class="muted">{{ __('admin.emails.import.plus_addressing_help') }}</p>
            @if($probe !== null)
                <p class="muted" data-probe-state>{{ __('admin.emails.import.probe_label', ['at' => $probe['sent_at']->format('Y-m-d H:i')]) }} {{ $probe['state'] }}</p>
            @endif

            @if($canManage)
                <div class="actions">
                    <button type="submit">{{ __('admin.emails.import.save') }}</button>
                    <button type="submit" class="button button-secondary" formaction="{{ route('admin.emails.import.test') }}" data-test-connection>{{ __('admin.emails.import.test') }}</button>
                </div>
                <p class="muted">{{ __('admin.emails.import.test_help') }}</p>
            @endif
        </fieldset>
    </form>
</section>
</section>
