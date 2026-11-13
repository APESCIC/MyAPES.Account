<section class="panel" aria-labelledby="import-status-heading" data-import-status>
    <h2 id="import-status-heading">{{ __('admin.emails.import.status_heading') }}</h2>
    <dl class="detail-list">
        <div>
            <dt>{{ __('admin.emails.import.last_test') }}</dt>
            <dd data-last-test>
                @if($lastTest === null)
                    {{ __('admin.emails.import.never') }}
                @elseif($lastTest['ok'])
                    {{ __('admin.emails.import.last_test_ok', ['at' => $lastTest['at']->format('Y-m-d H:i')]) }}
                @else
                    {{ __('admin.emails.import.last_test_failed', ['at' => $lastTest['at']->format('Y-m-d H:i'), 'reason' => $lastTest['report']->failure?->message()]) }}
                @endif
            </dd>
        </div>
        <div>
            <dt>{{ __('admin.emails.import.last_successful_poll') }}</dt>
            <dd data-last-poll>{{ $lastSuccessfulPollAt?->format('Y-m-d H:i') ?? __('admin.emails.import.never') }}</dd>
        </div>
        @if($nextPollAt !== null)
            <div>
                <dt>{{ __('admin.emails.import.next_poll') }}</dt>
                <dd data-next-poll>{{ __('admin.emails.import.backing_off', ['count' => $consecutiveFailures, 'at' => $nextPollAt->format('Y-m-d H:i')]) }}</dd>
            </div>
        @endif
        @if($lastPoll !== null && $lastPoll['result'] !== 'polled')
            <div>
                <dt>{{ __('admin.emails.import.last_poll') }}</dt>
                <dd>{{ __('admin.emails.import.last_poll_unavailable', ['at' => $lastPoll['at']->format('Y-m-d H:i')]) }}</dd>
            </div>
        @endif
    </dl>

    @can('admin.settings.manage')
        <div class="actions">
            <form method="post" action="{{ route('admin.emails.import.probe') }}" data-probe-form>
                @csrf
                <button type="submit" class="button button-secondary">{{ __('admin.emails.import.probe') }}</button>
            </form>
        </div>
        <p class="muted">{{ __('admin.emails.import.probe_help') }}</p>
    @endcan
</section>
