<section class="panel" aria-labelledby="mailbox-connection-heading" data-mailbox-connection>
    <h2 id="mailbox-connection-heading">{{ __('admin.emails.import.connection_heading') }}</h2>
    <p>{{ __('admin.emails.import.connection_built_in') }}</p>
    <p>
        <a href="{{ route('admin.settings.show', 'keys-and-secrets') }}">{{ __('admin.emails.import.keys_and_secrets_link') }}</a>
    </p>

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
