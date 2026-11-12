<section class="panel" aria-labelledby="api-docs-heading" data-settings-tab="api-docs">
    <h2 id="api-docs-heading">{{ __('admin.settings.api_docs.heading') }}</h2>
    <p>{{ __('admin.settings.api_docs.intro') }}</p>
    <p><a href="{{ $specUrl }}" rel="noopener noreferrer" target="_blank" data-api-spec-link>{{ __('admin.settings.api_docs.download') }}</a></p>
</section>

<section class="panel" aria-labelledby="api-docs-auth-heading">
    <h2 id="api-docs-auth-heading">{{ __('admin.settings.api_docs.auth_heading') }}</h2>
    <p>{{ __('admin.settings.api_docs.auth_body') }}</p>
    <pre><code>curl -H "Authorization: Bearer $MYAPES_TOKEN" {{ url('/api/v1/me') }}</code></pre>
    <p class="muted">{{ __('admin.settings.api_docs.errors_body') }}</p>
</section>

<section class="panel" aria-labelledby="api-docs-endpoints-heading" data-api-docs-endpoints>
    <h2 id="api-docs-endpoints-heading">{{ __('admin.settings.api_docs.endpoints_heading') }}</h2>
    <table>
        <caption>{{ __('admin.settings.api_docs.endpoints_caption') }}</caption>
        <thead>
            <tr>
                <th scope="col">{{ __('admin.settings.api_docs.method') }}</th>
                <th scope="col">{{ __('admin.settings.api_docs.path') }}</th>
                <th scope="col">{{ __('admin.settings.api_docs.ability') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($endpoints as $endpoint)
                <tr>
                    <td>{{ $endpoint['method'] }}</td>
                    <td><code>{{ $endpoint['path'] }}</code></td>
                    <td>@if($endpoint['ability'])<code>{{ $endpoint['ability'] }}</code>@else{{ __('admin.settings.api_docs.no_ability') }}@endif</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="muted">{{ __('admin.settings.api_docs.scope_body') }}</p>
</section>

<section class="panel" aria-labelledby="api-docs-webhooks-heading" data-api-docs-webhooks>
    <h2 id="api-docs-webhooks-heading">{{ __('admin.settings.api_docs.webhooks_heading') }}</h2>
    <p>{{ __('admin.settings.api_docs.webhooks_body', ['attempts' => $maxAttempts, 'disable' => $disableAfter]) }}</p>
    <ul>
        @foreach($events as $event => $app)
            <li><code>{{ $event }}</code> <span class="muted">({{ $app === 'newsroom' ? 'APES Newsroom' : 'EASM' }})</span></li>
        @endforeach
    </ul>
    <h3>{{ __('admin.settings.api_docs.signature_heading') }}</h3>
    <p>{{ __('admin.settings.api_docs.signature_body', ['header' => $signatureHeader, 'event' => $eventHeader, 'id' => $eventIdHeader, 'seconds' => $tolerance]) }}</p>
    <pre data-api-docs-verifier><code>// PHP: check a MyAPES Account webhook before trusting it.
function myapes_webhook_is_valid(string $body, string $header, string $secret, int $tolerance = {{ $tolerance }}): bool
{
    $parts = [];
    foreach (explode(',', $header) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        $parts[$key] = $value;
    }
    if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t'])) {
        return false;
    }
    if (abs(time() - (int) $parts['t']) > $tolerance) {
        return false; // too old (or from the future): a replayed request
    }

    return hash_equals(hash_hmac('sha256', $parts['t'].'.'.$body, $secret), $parts['v1']);
}

// $body = file_get_contents('php://input');
// myapes_webhook_is_valid($body, $_SERVER['HTTP_X_MYAPES_SIGNATURE'] ?? '', getenv('MYAPES_WEBHOOK_SECRET'));</code></pre>
</section>
