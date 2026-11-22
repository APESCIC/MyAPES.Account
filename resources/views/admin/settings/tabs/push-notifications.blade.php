<section class="panel" aria-labelledby="push-notifications-heading" data-settings-tab="push-notifications">
    <h2 id="push-notifications-heading">{{ __('admin.settings.push_notifications.heading') }}</h2>
    <p>{{ __('admin.settings.push_notifications.intro') }}</p>
</section>

<section class="panel" aria-labelledby="push-vapid-heading" data-push-vapid-status>
    <h2 id="push-vapid-heading">{{ __('admin.settings.push_notifications.vapid.heading') }}</h2>
    <p>{{ __('admin.settings.push_notifications.vapid.intro') }}</p>

    <p data-push-configured="{{ $configured ? '1' : '0' }}">
        <strong>{{ $statusLabel }}</strong>
    </p>

    @if($fingerprint !== null)
        <p data-push-fingerprint>{{ __('admin.settings.push_notifications.vapid.fingerprint', ['value' => $fingerprint]) }}</p>
    @endif

    <p data-push-source="{{ $source }}">
        @if($source === 'keys_and_secrets' && $set_at)
            {{ __('admin.settings.push_notifications.vapid.status.set', [
                'date' => $set_at->timezone(config('app.timezone'))->format('j M Y H:i'),
                'user' => $set_by_name ?? __('admin.settings.keys_and_secrets.status.unknown_user'),
            ]) }}
        @elseif($source === 'env')
            {{ __('admin.settings.push_notifications.vapid.status.env') }}
        @else
            {{ __('admin.settings.push_notifications.vapid.status.not_set') }}
        @endif
    </p>
    <p class="muted">{{ __("admin.settings.push_notifications.vapid.active_source.{$source}") }}</p>

    @can('admin.settings.manage')
        <form method="post" action="{{ route('admin.settings.push-notifications.vapid.store') }}" class="stacked-form" autocomplete="off" data-push-vapid-form="set">
            @csrf
            @method('PUT')
            <label for="push-vapid-subject">{{ __('admin.settings.push_notifications.vapid.fields.subject') }}</label>
            <input id="push-vapid-subject" name="subject" type="text" maxlength="500" autocomplete="off" required>
            <label for="push-vapid-public">{{ __('admin.settings.push_notifications.vapid.fields.public_key') }}</label>
            <input id="push-vapid-public" name="public_key" type="password" maxlength="500" autocomplete="new-password" required>
            <label for="push-vapid-private">{{ __('admin.settings.push_notifications.vapid.fields.private_key') }}</label>
            <input id="push-vapid-private" name="private_key" type="password" maxlength="500" autocomplete="new-password" required>
            <p class="muted">{{ __('admin.settings.push_notifications.vapid.fields.help') }}</p>
            <p class="muted">{{ __('admin.settings.push_notifications.subscriptions_warning') }}</p>
            <label class="inline-check">
                <input type="checkbox" name="subscriptions_confirm" value="1" required>
                <span>{{ __('admin.settings.push_notifications.subscriptions_confirm') }}</span>
            </label>
            <div class="actions">
                <button type="submit">{{ $source === 'keys_and_secrets' ? __('admin.settings.push_notifications.vapid.replace') : __('admin.settings.push_notifications.vapid.save') }}</button>
            </div>
        </form>

        <form method="post" action="{{ route('admin.settings.push-notifications.vapid.generate') }}" class="stacked-form" data-push-vapid-form="generate">
            @csrf
            <p class="muted">{{ __('admin.settings.push_notifications.vapid.generate_help') }}</p>
            <p class="muted">{{ __('admin.settings.push_notifications.subscriptions_warning') }}</p>
            <label class="inline-check">
                <input type="checkbox" name="subscriptions_confirm" value="1" required>
                <span>{{ __('admin.settings.push_notifications.subscriptions_confirm') }}</span>
            </label>
            <div class="actions">
                <button type="submit">{{ __('admin.settings.push_notifications.vapid.generate') }}</button>
            </div>
        </form>

        @if($source === 'keys_and_secrets')
            <form method="post" action="{{ route('admin.settings.push-notifications.vapid.clear') }}" class="stacked-form" data-push-vapid-form="clear">
                @csrf
                @method('DELETE')
                <p class="muted">{{ __('admin.settings.push_notifications.subscriptions_warning') }}</p>
                <label class="inline-check">
                    <input type="checkbox" name="confirm" value="1" required>
                    <span>{{ __('admin.settings.push_notifications.vapid.clear_confirm_check') }}</span>
                </label>
                <label class="inline-check">
                    <input type="checkbox" name="subscriptions_confirm" value="1" required>
                    <span>{{ __('admin.settings.push_notifications.subscriptions_confirm') }}</span>
                </label>
                <div class="actions">
                    <button type="submit" class="button-danger">{{ __('admin.settings.push_notifications.vapid.clear') }}</button>
                </div>
            </form>
        @endif

        <form method="post" action="{{ route('admin.settings.push-notifications.test') }}" class="stacked-form" data-push-test-form>
            @csrf
            <p class="muted">{{ __('admin.settings.push_notifications.test_push.help') }}</p>
            <div class="actions">
                <button type="submit">{{ __('admin.settings.push_notifications.test_push.button') }}</button>
            </div>
        </form>
    @endcan
</section>

<section class="panel" aria-labelledby="push-pause-heading" data-push-pause>
    <h2 id="push-pause-heading">{{ __('admin.settings.push_notifications.pause.heading') }}</h2>
    <p>{{ __('admin.settings.push_notifications.pause.intro') }}</p>
    @can('admin.settings.manage')
        <form method="post" action="{{ route('admin.settings.push-notifications.pause.update') }}" class="stacked-form">
            @csrf
            @method('PUT')
            <input type="hidden" name="paused" value="0">
            <label class="inline-check">
                <input type="checkbox" name="paused" value="1" @checked($paused)>
                <span>{{ __('admin.settings.push_notifications.pause.label') }}</span>
            </label>
            <div class="actions">
                <button type="submit">{{ __('admin.settings.push_notifications.pause.save') }}</button>
            </div>
        </form>
    @else
        <p data-push-paused="{{ $paused ? '1' : '0' }}">
            {{ $paused ? __('admin.settings.push_notifications.pause.on') : __('admin.settings.push_notifications.pause.off') }}
        </p>
    @endcan
</section>

<section class="panel" aria-labelledby="push-stats-heading" data-push-stats>
    <h2 id="push-stats-heading">{{ __('admin.settings.push_notifications.stats.heading') }}</h2>
    <p>{{ __('admin.settings.push_notifications.stats.intro') }}</p>

    <h3>{{ __('admin.settings.push_notifications.stats.devices_heading') }}</h3>
    <dl class="detail-list" data-push-device-counts>
        @foreach($deviceCounts as $role => $count)
            <div>
                <dt>{{ __('admin.settings.push_notifications.stats.roles.'.$role) }}</dt>
                <dd>{{ $count }}</dd>
            </div>
        @endforeach
    </dl>

    <h3>{{ __('admin.settings.push_notifications.stats.deliveries_heading') }}</h3>
    @if($deliveryCounts === [])
        <p class="muted">{{ __('admin.settings.push_notifications.stats.deliveries_empty') }}</p>
    @else
        <table class="data-table" data-push-delivery-counts>
            <thead>
                <tr>
                    <th scope="col">{{ __('admin.settings.push_notifications.stats.type') }}</th>
                    <th scope="col">{{ __('admin.settings.push_notifications.stats.sent') }}</th>
                    <th scope="col">{{ __('admin.settings.push_notifications.stats.failed') }}</th>
                    <th scope="col">{{ __('admin.settings.push_notifications.stats.expired') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($deliveryCounts as $type => $totals)
                    <tr>
                        <td>{{ __('push.types.'.$type, [], false) !== 'push.types.'.$type ? __('push.types.'.$type) : $type }}</td>
                        <td>{{ $totals['sent'] }}</td>
                        <td>{{ $totals['failed'] }}</td>
                        <td>{{ $totals['expired'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>