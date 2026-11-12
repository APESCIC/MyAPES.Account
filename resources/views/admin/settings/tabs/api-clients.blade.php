@php($canManage = auth()->user()?->can('admin.settings.manage'))

@if(is_array($newToken))
    <section class="panel" aria-labelledby="api-new-token-heading" data-new-api-token>
        <h2 id="api-new-token-heading">{{ __('admin.settings.api_clients.new_token_heading', ['name' => $newToken['client']]) }}</h2>
        <p class="error-list" role="status">{{ __('admin.settings.api_clients.new_token_once') }}</p>
        <label for="api-new-token">{{ __('admin.settings.api_clients.new_token_label') }}</label>
        <input id="api-new-token" type="text" readonly value="{{ $newToken['token'] }}" autocomplete="off" spellcheck="false" data-new-api-token-value>
    </section>
@endif

<section class="panel" aria-labelledby="api-clients-heading" data-settings-tab="api-clients">
    <h2 id="api-clients-heading">{{ __('admin.settings.api_clients.heading') }}</h2>
    <p>{{ __('admin.settings.api_clients.intro') }}</p>

    @if($clients->isEmpty())
        <p class="muted" data-api-clients-empty>{{ __('admin.settings.api_clients.empty') }}</p>
    @endif

    @foreach($clients as $client)
        <article class="panel" data-api-client="{{ $client->id }}" @if($client->isRevoked()) data-api-client-revoked @endif>
            <h3>{{ $client->name }}
                <span class="status">{{ $client->isRevoked() ? __('admin.settings.api_clients.status_revoked') : __('admin.settings.api_clients.status_active') }}</span>
            </h3>
            <dl class="detail-list">
                <div><dt>{{ __('admin.settings.api_clients.organisation') }}</dt><dd>{{ $client->organisation }}</dd></div>
                <div><dt>{{ __('admin.settings.api_clients.abilities') }}</dt><dd><code>{{ implode(', ', $client->abilities) }}</code></dd></div>
                <div><dt>{{ __('admin.settings.api_clients.ip_allowlist') }}</dt><dd>{{ $client->ip_allowlist ? implode(', ', $client->ip_allowlist) : __('admin.settings.api_clients.any_ip') }}</dd></div>
                <div><dt>{{ __('admin.settings.api_clients.rate_limit') }}</dt><dd>{{ __('admin.settings.api_clients.per_minute', ['count' => $client->rate_limit_per_minute]) }}</dd></div>
                <div><dt>{{ __('admin.settings.api_clients.last_used') }}</dt><dd data-api-client-last-used>
                    {{ $client->last_used_at ? __('admin.settings.api_clients.last_used_value', ['at' => $client->last_used_at->format('Y-m-d H:i'), 'ip' => $client->last_used_ip]) : __('admin.settings.api_clients.never') }}
                </dd></div>
            </dl>

            @if($client->tokens->isNotEmpty())
                <table>
                    <caption>{{ __('admin.settings.api_clients.tokens_caption', ['name' => $client->name]) }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.settings.api_clients.token_created') }}</th>
                            <th scope="col">{{ __('admin.settings.api_clients.token_expires') }}</th>
                            <th scope="col">{{ __('admin.settings.api_clients.token_last_used') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($client->tokens as $token)
                            <tr>
                                <td>{{ $token->created_at?->format('Y-m-d H:i') }}</td>
                                <td>{{ $token->expires_at?->format('Y-m-d H:i') ?? __('admin.settings.api_clients.never') }}@if($token->expires_at?->isPast()) ({{ __('admin.settings.api_clients.expired') }})@endif</td>
                                <td>{{ $token->last_used_at?->format('Y-m-d H:i') ?? __('admin.settings.api_clients.never') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if($canManage && ! $client->isRevoked())
                <form method="post" action="{{ route('admin.settings.api-clients.update', $client) }}" class="stacked-form" data-api-client-update-form>
                    @csrf
                    @method('PUT')
                    <label for="api-client-{{ $client->id }}-ips">{{ __('admin.settings.api_clients.ip_allowlist') }}</label>
                    <textarea id="api-client-{{ $client->id }}-ips" name="ip_allowlist" rows="2" maxlength="2000">{{ implode("\n", $client->ip_allowlist ?? []) }}</textarea>
                    <p class="muted">{{ __('admin.settings.api_clients.ip_help') }}</p>
                    <label for="api-client-{{ $client->id }}-rate">{{ __('admin.settings.api_clients.rate_limit') }}</label>
                    <input id="api-client-{{ $client->id }}-rate" name="rate_limit_per_minute" type="number" min="1" max="1000" value="{{ $client->rate_limit_per_minute }}">
                    <div class="actions"><button type="submit" class="button button-secondary">{{ __('admin.settings.api_clients.save_access') }}</button></div>
                </form>
                <div class="actions">
                    <form method="post" action="{{ route('admin.settings.api-clients.rotate', $client) }}" data-api-client-rotate-form>
                        @csrf
                        <input type="hidden" name="expires_in_days" value="{{ $defaultExpiryDays }}">
                        <button type="submit" class="button button-secondary">{{ __('admin.settings.api_clients.rotate') }}</button>
                    </form>
                    <form method="post" action="{{ route('admin.settings.api-clients.revoke', $client) }}" data-api-client-revoke-form data-confirm="{{ __('admin.settings.api_clients.revoke_confirm', ['name' => $client->name]) }}">
                        @csrf
                        <button type="submit" class="button button-secondary">{{ __('admin.settings.api_clients.revoke') }}</button>
                    </form>
                </div>
                <p class="muted">{{ __('admin.settings.api_clients.rotate_help', ['hours' => \App\Core\Api\ApiClientManager::ROTATION_OVERLAP_HOURS, 'days' => $defaultExpiryDays]) }}</p>
            @endif
        </article>
    @endforeach
</section>

@if($canManage)
    <section class="panel" aria-labelledby="api-client-create-heading" data-api-client-create>
        <h2 id="api-client-create-heading">{{ __('admin.settings.api_clients.create_heading') }}</h2>
        <div class="actions">
            @foreach($presets as $presetKey => $preset)
                <form method="post" action="{{ route('admin.settings.api-clients.store') }}" data-api-client-preset="{{ $presetKey }}">
                    @csrf
                    <input type="hidden" name="name" value="{{ $preset['name'] }}">
                    <input type="hidden" name="organisation" value="{{ $preset['organisation'] }}">
                    @foreach($preset['abilities'] as $ability)
                        <input type="hidden" name="abilities[]" value="{{ $ability }}">
                    @endforeach
                    <input type="hidden" name="expires_in_days" value="{{ $defaultExpiryDays }}">
                    <input type="hidden" name="rate_limit_per_minute" value="60">
                    <button type="submit" class="button button-secondary">{{ __('admin.settings.api_clients.create_preset', ['name' => $preset['name']]) }}</button>
                </form>
            @endforeach
        </div>
        <p class="muted">{{ __('admin.settings.api_clients.presets_help') }}</p>

        <form method="post" action="{{ route('admin.settings.api-clients.store') }}" class="stacked-form" data-api-client-create-form autocomplete="off">
            @csrf
            <label for="api-client-name">{{ __('admin.settings.api_clients.name') }}</label>
            <input id="api-client-name" name="name" type="text" maxlength="100" required value="{{ old('name') }}">

            <label for="api-client-organisation">{{ __('admin.settings.api_clients.organisation') }}</label>
            <select id="api-client-organisation" name="organisation" required>
                @foreach($organisations as $organisation)
                    <option value="{{ $organisation }}" @selected(old('organisation') === $organisation)>{{ $organisation }}</option>
                @endforeach
            </select>

            <fieldset>
                <legend>{{ __('admin.settings.api_clients.abilities') }}</legend>
                @foreach($abilities as $ability)
                    <label class="inline-check">
                        <input type="checkbox" name="abilities[]" value="{{ $ability }}" @checked(in_array($ability, (array) old('abilities', []), true))>
                        <code>{{ $ability }}</code>
                    </label>
                @endforeach
            </fieldset>

            <label for="api-client-expiry">{{ __('admin.settings.api_clients.expires_in_days') }}</label>
            <input id="api-client-expiry" name="expires_in_days" type="number" min="1" max="{{ $maxExpiryDays }}" value="{{ old('expires_in_days', $defaultExpiryDays) }}" required>

            <label for="api-client-ips">{{ __('admin.settings.api_clients.ip_allowlist') }}</label>
            <textarea id="api-client-ips" name="ip_allowlist" rows="2" maxlength="2000">{{ old('ip_allowlist') }}</textarea>
            <p class="muted">{{ __('admin.settings.api_clients.ip_help') }}</p>

            <label for="api-client-rate">{{ __('admin.settings.api_clients.rate_limit') }}</label>
            <input id="api-client-rate" name="rate_limit_per_minute" type="number" min="1" max="1000" value="{{ old('rate_limit_per_minute', 60) }}" required>

            <div class="actions"><button type="submit">{{ __('admin.settings.api_clients.create') }}</button></div>
        </form>
    </section>
@endif
