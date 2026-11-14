<section class="panel" aria-labelledby="keys-secrets-heading" data-settings-tab="keys-and-secrets">
    <h2 id="keys-secrets-heading">{{ __('admin.settings.keys_and_secrets.heading') }}</h2>
    <p>{{ __('admin.settings.keys_and_secrets.intro') }}</p>
</section>

<section class="panel" aria-labelledby="mailbox-password-heading" data-secret="inbound_mail_imap_password">
    <h2 id="mailbox-password-heading">{{ __('admin.settings.keys_and_secrets.mailbox_password.heading') }}</h2>
    <p>{{ __('admin.settings.keys_and_secrets.mailbox_password.intro') }}</p>

    <p data-secret-status="{{ $mailboxPassword['source'] }}">
        @if($mailboxPassword['source'] === 'keys_and_secrets' && $mailboxPassword['set_at'])
            {{ __('admin.settings.keys_and_secrets.status.set', [
                'date' => $mailboxPassword['set_at']->timezone(config('app.timezone'))->format('j M Y H:i'),
                'user' => $mailboxPassword['set_by_name'] ?? __('admin.settings.keys_and_secrets.status.unknown_user'),
            ]) }}
        @elseif($mailboxPassword['active_source'] === 'env' || $mailboxPassword['source'] === 'env')
            {{ __('admin.settings.keys_and_secrets.status.env') }}
        @else
            {{ __('admin.settings.keys_and_secrets.status.not_set') }}
        @endif
    </p>
    <p class="muted" data-secret-active-source="{{ $mailboxPassword['active_source'] }}">
        {{ __("admin.settings.keys_and_secrets.active_source.{$mailboxPassword['active_source']}") }}
    </p>

    @can('admin.settings.manage')
        <form method="post" action="{{ route('admin.settings.keys-and-secrets.store') }}" class="stacked-form" autocomplete="off" data-secret-form="set">
            @csrf
            @method('PUT')
            <label for="secret-mailbox-password">{{ __('admin.settings.keys_and_secrets.mailbox_password.label') }}</label>
            <input id="secret-mailbox-password" name="password" type="password" maxlength="500" autocomplete="new-password" required>
            <p class="muted">{{ __('admin.settings.keys_and_secrets.mailbox_password.help') }}</p>
            <div class="actions">
                <button type="submit">{{ $mailboxPassword['source'] === 'keys_and_secrets' ? __('admin.settings.keys_and_secrets.replace') : __('admin.settings.keys_and_secrets.save') }}</button>
            </div>
        </form>

        <form method="post" action="{{ route('admin.settings.keys-and-secrets.test') }}" class="stacked-form" autocomplete="off" data-secret-form="test">
            @csrf
            <label for="secret-mailbox-password-test">{{ __('admin.settings.keys_and_secrets.test_label') }}</label>
            <input id="secret-mailbox-password-test" name="password" type="password" maxlength="500" autocomplete="new-password">
            <p class="muted">{{ __('admin.settings.keys_and_secrets.test_help') }}</p>
            <div class="actions">
                <button type="submit">{{ __('admin.settings.keys_and_secrets.test') }}</button>
            </div>
        </form>

        @if($mailboxPassword['source'] === 'keys_and_secrets')
            <form method="post" action="{{ route('admin.settings.keys-and-secrets.destroy') }}" class="stacked-form" data-secret-form="clear" data-confirm="{{ __('admin.settings.keys_and_secrets.clear_confirm') }}">
                @csrf
                @method('DELETE')
                <label class="inline-check">
                    <input type="checkbox" name="confirm" value="1" required>
                    <span>{{ __('admin.settings.keys_and_secrets.clear_confirm_check') }}</span>
                </label>
                <div class="actions">
                    <button type="submit" class="button-danger">{{ __('admin.settings.keys_and_secrets.clear') }}</button>
                </div>
            </form>
        @endif
    @endcan

    @if(is_array(session('mailbox_report')))
        @include('admin.emails.import._result', ['report' => \App\Core\Mail\Inbound\Diagnostics\DiagnosticReport::fromArray(session('mailbox_report'))])
    @endif
</section>

<section class="panel" aria-labelledby="server-only-heading" data-secret-server-only>
    <h2 id="server-only-heading">{{ __('admin.settings.keys_and_secrets.server_only.heading') }}</h2>
    <p>{{ __('admin.settings.keys_and_secrets.server_only.intro') }}</p>
    <dl class="detail-list">
        @foreach($serverOnly as $secret)
            <div>
                <dt>{{ $secret['name'] }}</dt>
                <dd data-server-secret="{{ $secret['name'] }}" data-state="{{ $secret['state'] }}">
                    {{ $secret['label'] }}
                    <span class="muted">{{ __('admin.settings.keys_and_secrets.server_only.why') }}</span>
                </dd>
            </div>
        @endforeach
    </dl>
</section>
