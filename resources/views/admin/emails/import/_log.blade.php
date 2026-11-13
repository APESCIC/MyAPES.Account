{{-- Recent connection log (#780): fixed values only, never a host, username, password or message content. --}}
<section class="panel" aria-labelledby="import-log-heading" data-import-log>
    <h2 id="import-log-heading">{{ __('admin.emails.import.log.heading') }}</h2>
    <p class="muted">{{ __('admin.emails.import.log.intro', ['count' => \App\Core\Mail\Inbound\InboundMailboxLogEntry::KEEP, 'days' => \App\Core\Mail\Inbound\InboundMailboxLogEntry::RETENTION_DAYS]) }}</p>
    @if($log->isEmpty())
        <p data-import-log-empty>{{ __('admin.emails.import.log.empty') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">{{ __('admin.emails.import.log.caption') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.emails.import.log.when') }}</th>
                        <th scope="col">{{ __('admin.emails.import.log.kind') }}</th>
                        <th scope="col">{{ __('admin.emails.import.log.result') }}</th>
                        <th scope="col">{{ __('admin.emails.import.log.step') }}</th>
                        <th scope="col">{{ __('admin.emails.import.log.duration') }}</th>
                        <th scope="col">{{ __('admin.emails.import.log.counts') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($log as $entry)
                        <tr data-log-entry="{{ $entry->kind }}">
                            <td>{{ $entry->created_at->format('Y-m-d H:i:s') }}</td>
                            <td>{{ __("admin.emails.import.log.kinds.{$entry->kind}") }}</td>
                            <td>{{ $entry->resultLabel() }}</td>
                            <td>{{ $entry->step !== null ? __("admin.emails.import.steps.{$entry->step}") : '' }}</td>
                            <td>{{ __('admin.emails.import.step_time', ['ms' => $entry->duration_ms]) }}</td>
                            <td>
                                @if($entry->kind === \App\Core\Mail\Inbound\InboundMailboxLogEntry::KIND_POLL)
                                    {{ __('admin.emails.import.log.counts_value', ['fetched' => $entry->fetched ?? 0, 'stored' => $entry->stored ?? 0, 'duplicates' => $entry->duplicates ?? 0, 'failed' => $entry->failed ?? 0]) }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
