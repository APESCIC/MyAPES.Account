@php($canManage = auth()->user()?->can('admin.settings.manage'))

@if(is_array($newSecret))
    <section class="panel" aria-labelledby="webhook-new-secret-heading" data-new-webhook-secret>
        <h2 id="webhook-new-secret-heading">{{ __('admin.settings.webhooks.new_secret_heading', ['name' => $newSecret['endpoint']]) }}</h2>
        <p class="error-list" role="status">{{ __('admin.settings.webhooks.new_secret_once') }}</p>
        <label for="webhook-new-secret">{{ __('admin.settings.webhooks.new_secret_label') }}</label>
        <input id="webhook-new-secret" type="text" readonly value="{{ $newSecret['secret'] }}" autocomplete="off" spellcheck="false">
    </section>
@endif

<section class="panel" aria-labelledby="webhooks-heading" data-settings-tab="webhooks">
    <h2 id="webhooks-heading">{{ __('admin.settings.webhooks.heading') }}</h2>
    <p>{{ __('admin.settings.webhooks.intro') }}</p>

    @if($endpoints->isEmpty())
        <p class="muted" data-webhooks-empty>{{ __('admin.settings.webhooks.empty') }}</p>
    @endif

    @foreach($endpoints as $endpoint)
        <article class="panel" data-webhook-endpoint="{{ $endpoint->id }}">
            <h3>{{ $endpoint->name }}
                <span class="status">{{ $endpoint->active ? __('admin.settings.webhooks.status_active') : __('admin.settings.webhooks.status_off') }}</span>
            </h3>
            @unless($endpoint->active)
                @if($endpoint->disabled_reason)
                    <p class="error-list" role="status" data-webhook-disabled-reason>{{ $endpoint->disabled_reason }}</p>
                @endif
            @endunless
            <dl class="detail-list">
                <div><dt>{{ __('admin.settings.webhooks.url') }}</dt><dd><code>{{ $endpoint->url }}</code></dd></div>
                <div><dt>{{ __('admin.settings.webhooks.organisation') }}</dt><dd>{{ $endpoint->organisation }}</dd></div>
                <div><dt>{{ __('admin.settings.webhooks.events') }}</dt><dd><code>{{ implode(', ', $endpoint->events) }}</code></dd></div>
                <div><dt>{{ __('admin.settings.webhooks.failures') }}</dt><dd>{{ $endpoint->consecutive_failures }}</dd></div>
            </dl>
            @if($canManage)
                <div class="actions">
                    <form method="post" action="{{ route('admin.settings.webhooks.toggle', $endpoint) }}">
                        @csrf
                        <button type="submit" class="button button-secondary">{{ $endpoint->active ? __('admin.settings.webhooks.turn_off') : __('admin.settings.webhooks.turn_on') }}</button>
                    </form>
                    <form method="post" action="{{ route('admin.settings.webhooks.secret', $endpoint) }}" data-confirm="{{ __('admin.settings.webhooks.rotate_confirm', ['name' => $endpoint->name]) }}">
                        @csrf
                        <button type="submit" class="button button-secondary">{{ __('admin.settings.webhooks.rotate_secret') }}</button>
                    </form>
                    <form method="post" action="{{ route('admin.settings.webhooks.destroy', $endpoint) }}" data-confirm="{{ __('admin.settings.webhooks.delete_confirm', ['name' => $endpoint->name]) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="button button-secondary">{{ __('admin.settings.webhooks.delete') }}</button>
                    </form>
                </div>
            @endif
        </article>
    @endforeach
</section>

<section class="panel" aria-labelledby="webhook-log-heading" data-webhook-log>
    <h2 id="webhook-log-heading">{{ __('admin.settings.webhooks.log_heading') }}</h2>
    <p class="muted">{{ __('admin.settings.webhooks.log_intro') }}</p>
    @if($deliveries->isEmpty())
        <p class="muted">{{ __('admin.settings.webhooks.log_empty') }}</p>
    @else
        <table>
            <caption>{{ __('admin.settings.webhooks.log_caption') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('admin.settings.webhooks.log_when') }}</th>
                    <th scope="col">{{ __('admin.settings.webhooks.log_endpoint') }}</th>
                    <th scope="col">{{ __('admin.settings.webhooks.log_event') }}</th>
                    <th scope="col">{{ __('admin.settings.webhooks.log_status') }}</th>
                    <th scope="col">{{ __('admin.settings.webhooks.log_attempts') }}</th>
                    <th scope="col"><span class="visually-hidden">{{ __('admin.settings.webhooks.log_actions') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach($deliveries as $delivery)
                    @php($statusKey = 'admin.settings.webhooks.delivery_status.'.$delivery->status)
                    <tr data-webhook-delivery="{{ $delivery->id }}" data-webhook-delivery-status="{{ $delivery->status }}">
                        <td>{{ $delivery->created_at?->format('Y-m-d H:i:s') }}</td>
                        <td>{{ $delivery->endpoint?->name }}</td>
                        <td><code>{{ $delivery->event }}</code><br><span class="muted">{{ $delivery->event_id }}</span></td>
                        <td>{{ __($statusKey) }}@if($delivery->next_attempt_at) <br><span class="muted">{{ __('admin.settings.webhooks.next_try', ['at' => $delivery->next_attempt_at->format('Y-m-d H:i')]) }}</span>@endif</td>
                        <td>
                            <ol data-webhook-attempts>
                                @foreach($delivery->attemptLog as $attempt)
                                    <li>{{ __('admin.settings.webhooks.attempt_line', ['at' => $attempt->created_at?->format('H:i:s'), 'status' => $attempt->status_code ?? '-', 'ms' => $attempt->duration_ms]) }}@if($attempt->error) <span class="muted">{{ $attempt->error }}</span>@endif</li>
                                @endforeach
                            </ol>
                        </td>
                        <td>
                            @if($canManage && $delivery->endpoint?->active)
                                <form method="post" action="{{ route('admin.settings.webhooks.replay', $delivery) }}" data-webhook-replay-form>
                                    @csrf
                                    <button type="submit" class="button button-secondary">{{ __('admin.settings.webhooks.replay') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>

@if($canManage)
    <section class="panel" aria-labelledby="webhook-create-heading" data-webhook-create>
        <h2 id="webhook-create-heading">{{ __('admin.settings.webhooks.create_heading') }}</h2>
        <form method="post" action="{{ route('admin.settings.webhooks.store') }}" class="stacked-form" autocomplete="off">
            @csrf
            <label for="webhook-name">{{ __('admin.settings.webhooks.name') }}</label>
            <input id="webhook-name" name="name" type="text" maxlength="100" required value="{{ old('name') }}">

            <label for="webhook-url">{{ __('admin.settings.webhooks.url') }}</label>
            <input id="webhook-url" name="url" type="url" maxlength="500" required placeholder="https://" value="{{ old('url') }}">
            <p class="muted">{{ __('admin.settings.webhooks.https_only') }}</p>

            <label for="webhook-organisation">{{ __('admin.settings.webhooks.organisation') }}</label>
            <select id="webhook-organisation" name="organisation" required>
                @foreach($organisations as $organisation)
                    <option value="{{ $organisation }}" @selected(old('organisation') === $organisation)>{{ $organisation }}</option>
                @endforeach
            </select>

            <fieldset>
                <legend>{{ __('admin.settings.webhooks.events') }}</legend>
                @foreach($events as $event => $app)
                    <label class="inline-check">
                        <input type="checkbox" name="events[]" value="{{ $event }}" @checked(in_array($event, (array) old('events', []), true))>
                        <code>{{ $event }}</code> <span class="muted">({{ $app === 'newsroom' ? 'APES Newsroom' : 'EASM' }})</span>
                    </label>
                @endforeach
            </fieldset>

            <div class="actions"><button type="submit">{{ __('admin.settings.webhooks.create') }}</button></div>
        </form>
    </section>
@endif
