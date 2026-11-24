<section class="panel" id="security-activity">
    <h2>{{ __('account.settings.security_activity.heading') }}</h2>
    <p class="muted">{{ __('account.settings.security_activity.intro') }}</p>

    @if($securityActivity === [])
        <p class="muted">{{ __('account.settings.security_activity.empty') }}</p>
    @else
        @php($securityEventLabels = __('account.settings.security_activity.events'))
        <table class="data-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('account.settings.security_activity.when') }}</th>
                    <th scope="col">{{ __('account.settings.security_activity.event') }}</th>
                    <th scope="col">{{ __('account.settings.security_activity.ip') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($securityActivity as $entry)
                    <tr>
                        <td>
                            @if($entry['created_at'])
                                <time datetime="{{ $entry['created_at']->toIso8601String() }}">
                                    {{ $entry['created_at']->format('d/m/Y H:i') }}
                                </time>
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $securityEventLabels[$entry['event']] ?? $entry['event'] }}</td>
                        <td>{{ $entry['ip_address'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>