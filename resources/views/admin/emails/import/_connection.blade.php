<section class="panel" aria-labelledby="mailbox-connection-heading" data-mailbox-connection>
    <h2 id="mailbox-connection-heading">{{ __('admin.emails.import.connection_heading') }}</h2>
    <p>{{ __('admin.emails.import.connection_built_in') }}</p>

    <dl class="detail-list" data-connection-panel>
        @foreach($connectionRows as $row)
            <div data-connection-row="{{ $row['key'] }}" data-source="{{ $row['source'] }}">
                <dt>{{ __('admin.emails.import.fields.'.$row['key']) }}</dt>
                <dd>
                    @if($row['secret'])
                        <span data-secret-status="{{ $row['source'] }}">
                            {{ __('admin.emails.import.sources.'.$row['source']) }}
                        </span>
                        @if($row['key'] === 'password')
                            <a href="{{ route('admin.settings.show', 'keys-and-secrets') }}">{{ __('admin.emails.import.keys_and_secrets_link') }}</a>
                        @elseif($row['key'] === 'token_key' && $row['source'] === 'missing')
                            <span class="muted">{{ __('admin.emails.import.token_key_it') }}</span>
                        @endif
                    @else
                        <span data-connection-value>{{ $row['value'] }}</span>
                        <span class="muted" data-connection-source>{{ __('admin.emails.import.sources.'.$row['source']) }}</span>
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>

    @php($canManage = auth()->user()?->can('admin.settings.manage'))
    @if($canManage)
        <form method="post" action="{{ route('admin.emails.import.test') }}" class="stacked-form" data-reply-mailbox-test autocomplete="off">
            @csrf
            <div class="actions">
                <button type="submit" data-test-connection>{{ __('admin.emails.import.test') }}</button>
            </div>
            <p class="muted">{{ __('admin.emails.import.test_help_built_in') }}</p>
        </form>
        @if($probe !== null)
            <p class="muted" data-probe-state>{{ __('admin.emails.import.probe_label', ['at' => $probe['sent_at']->format('Y-m-d H:i')]) }} {{ $probe['state'] }}</p>
        @endif
    @endif
</section>
