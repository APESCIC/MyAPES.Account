<section class="panel" aria-labelledby="import-queue-heading" data-import-queue>
    <h2 id="import-queue-heading">{{ __('admin.emails.import.queue.heading') }}</h2>
    <p class="muted">{{ __('admin.emails.import.queue.intro') }}</p>

    @if ($queue === [])
        <p data-import-queue-empty>{{ __('admin.emails.import.queue.empty') }}</p>
    @else
        <div class="table-wrap" role="region" aria-label="{{ __('admin.emails.import.queue.heading') }}" tabindex="0">
            <table>
                <caption>{{ __('admin.emails.import.queue.caption') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.emails.import.queue.col_age') }}</th>
                        <th scope="col">{{ __('admin.emails.import.queue.col_sender') }}</th>
                        <th scope="col">{{ __('admin.emails.import.queue.col_status') }}</th>
                        <th scope="col">{{ __('admin.emails.import.queue.col_reason') }}</th>
                        <th scope="col">{{ __('admin.emails.import.queue.col_subject') }}</th>
                        <th scope="col">{{ __('admin.emails.import.queue.col_actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($queue as $item)
                        <tr data-import-queue-row="{{ $item['id'] }}" data-import-queue-status="{{ $item['status'] }}">
                            <td data-import-queue-age>{{ $item['age'] }}</td>
                            <td>{{ $item['sender'] }}</td>
                            <td><span class="status">{{ $item['status_label'] }}</span></td>
                            <td data-import-queue-reason>{{ $item['reason'] ?? '—' }}</td>
                            <td>{{ $item['subject'] ?? '—' }}</td>
                            <td>
                                <a href="{{ $item['show_url'] }}">{{ __('admin.emails.import.queue.view') }}</a>
                                @can('admin.emails.manage')
                                    @if ($item['can_retry'])
                                        <form method="post" action="{{ route('admin.emails.inbound.retry', $item['id']) }}" class="inline-form">
                                            @csrf
                                            <button type="submit" class="button button-secondary">{{ __('admin.emails.inbound.retry') }}</button>
                                        </form>
                                    @endif
                                    @if ($item['can_attach'])
                                        <a href="{{ $item['show_url'] }}#inbound-actions-heading" data-import-queue-attach>{{ __('admin.emails.inbound.attach') }}</a>
                                    @endif
                                    @if ($item['can_discard'])
                                        <a href="{{ $item['show_url'] }}#inbound-actions-heading" data-import-queue-discard>{{ __('admin.emails.inbound.discard') }}</a>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @can('admin.emails.manage')
        @php($failedCount = collect($queue)->where('status', \App\Core\Mail\Inbound\InboundEmail::STATUS_FAILED)->where('can_retry', true)->count())
        @if ($failedCount > 0)
            <form method="post" action="{{ route('admin.emails.import.retry-failed') }}" class="stacked-form" data-import-retry-failed>
                @csrf
                <p class="muted">{{ __('admin.emails.import.queue.retry_failed_help', ['count' => $failedCount]) }}</p>
                <label class="inline-check">
                    <input type="checkbox" name="confirm_retry_failed" value="1" required>
                    <span>{{ __('admin.emails.import.queue.retry_failed_confirm', ['count' => $failedCount]) }}</span>
                </label>
                <div class="actions">
                    <button type="submit">{{ __('admin.emails.import.queue.retry_failed') }}</button>
                </div>
            </form>
        @endif
    @else
        <p class="muted" data-import-queue-readonly>{{ __('admin.emails.import.queue.readonly') }}</p>
    @endcan
</section>
