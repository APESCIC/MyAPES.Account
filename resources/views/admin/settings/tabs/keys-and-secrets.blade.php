@php($canManage = auth()->user()?->can('admin.settings.manage'))
@php($mailbox = $mailboxPassword)

<section class="panel" aria-labelledby="keys-secrets-heading" data-settings-tab="keys-and-secrets">
    <h2 id="keys-secrets-heading">{{ __('admin.settings.keys_and_secrets.heading') }}</h2>
    <p>{{ __('admin.settings.keys_and_secrets.intro') }}</p>
</section>

<section class="panel" aria-labelledby="mailbox-password-heading" data-secret="inbound_mail_imap_password">
    <h2 id="mailbox-password-heading">{{ __('admin.settings.keys_and_secrets.mailbox_password.heading') }}</h2>
    <p>{{ __('admin.settings.keys_and_secrets.mailbox_password.intro') }}</p>

    <p data-secret-status="{{ $mailbox['source'] }}">
        @if($mailbox['source'] === 'keys_and_secrets' && $mailbox['set_at'])
            {{ __('admin.settings.keys_and_secrets.status.set', [
                'date' => $mailbox['set_at']->timezone(config('app.timezone'))->format('j M Y H:i'),
                'user' => $mailbox['set_by_name'] ?? __('admin.settings.keys_and_secrets.status.unknown_user'),
            ]) }}
        @elseif($mailbox['active_source'] === 'env' || $mailbox['source'] === 'env')
            {{ __('admin.settings.keys_and_secrets.status.env') }}
        @else
            {{ __('admin.settings.keys_and_secrets.status.not_set') }}
        @endif
    </p>
    <p class="muted" data-secret-active-source="{{ $mailbox['active_source'] }}">
        {{ __("admin.settings.keys_and_secrets.active_source.{$mailbox['active_source']}") }}
    </p>

    @if($canManage)
        <form method="post" action="{{ route('admin.settings.keys-and-secrets.store') }}" class="stacked-form" autocomplete="off" data-secret-form="set">
            @csrf
            @method('PUT')
            <label for="secret-mailbox-password">{{ __('admin.settings.keys_and_secrets.mailbox_password.label') }}</label>
            <input id="secret-mailbox-password" name="password" type="password" maxlength="500" autocomplete="new-password" required>
            <p class="muted">{{ __('admin.settings.keys_and_secrets.mailbox_password.help') }}</p>
            <div class="actions">
                <button type="submit">{{ $mailbox['source'] === 'keys_and_secrets' ? __('admin.settings.keys_and_secrets.replace') : __('admin.settings.keys_and_secrets.save') }}</button>
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

        @if($mailbox['source'] === 'keys_and_secrets')
            <form method="post" action="{{ route('admin.settings.keys-and-secrets.destroy') }}" class="stacked-form" data-secret-form="clear" onsubmit="return confirm(@js(__('admin.settings.keys_and_secrets.clear_confirm')))">
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
    @endif

    @if(is_array(session('mailbox_report')))
        @include('admin.emails.import._result', ['report' => \App\Core\Mail\Inbound\Diagnostics\DiagnosticReport::fromArray(session('mailbox_report'))])
    @endif
</section>

<section class="panel" aria-labelledby="server-only-heading" data-secret-server-only>
    <h2 id="server-only-heading">{{ __('admin.settings.keys_and_secrets.server_only.heading') }}</h2>
    <p>{{ __('admin.settings.keys_and_secrets.server_only.intro') }}</p>
    <dl class="detail-list">
        @foreach($serverOnly as $name => $state)
            <div>
                <dt>{{ $name }}</dt>
                <dd data-server-secret="{{ $name }}" data-state="{{ $state }}">
                    {{ __("admin.settings.keys_and_secrets.server_only.{$state}") }}
                    <span class="muted">{{ __('admin.settings.keys_and_secrets.server_only.why') }}</span>
                </dd>
            </div>
        @endforeach
    </dl>
</section>
