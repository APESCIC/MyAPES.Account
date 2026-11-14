<section class="panel" aria-labelledby="import-monitor-heading" data-import-monitor>
    <h2 id="import-monitor-heading">{{ __('admin.emails.import.monitor.heading') }}</h2>
    <p class="muted">{{ __('admin.emails.import.monitor.intro') }}</p>

    <div class="import-health-strip" data-import-health="{{ $monitor['health']['state'] }}" role="status">
        <p class="import-health-state" data-health-state>{{ $monitor['health']['label'] }}</p>
        <dl class="detail-list import-health-meta">
            <div>
                <dt>{{ __('admin.emails.import.monitor.last_check') }}</dt>
                <dd data-health-last-check>{{ $monitor['health']['last_check']?->format('Y-m-d H:i') ?? __('admin.emails.import.never') }}</dd>
            </div>
            <div>
                <dt>{{ __('admin.emails.import.monitor.last_success') }}</dt>
                <dd data-health-last-success>{{ $monitor['health']['last_success']?->format('Y-m-d H:i') ?? __('admin.emails.import.never') }}</dd>
            </div>
            <div>
                <dt>{{ __('admin.emails.import.monitor.next_check') }}</dt>
                <dd data-health-next-check>{{ $monitor['health']['next_check']?->format('Y-m-d H:i') ?? __('admin.emails.import.never') }}</dd>
            </div>
            <div>
                <dt>{{ __('admin.emails.import.monitor.failures') }}</dt>
                <dd data-health-failures>{{ $monitor['health']['failures'] }}</dd>
            </div>
        </dl>
    </div>

    <div class="import-count-windows" data-import-counts>
        @foreach ([
            'hours_24' => ['label' => __('admin.emails.import.monitor.counts_24h'), 'data' => $monitor['counts']['hours_24']],
            'days_7' => ['label' => __('admin.emails.import.monitor.counts_7d'), 'data' => $monitor['counts']['days_7']],
        ] as $window => $block)
            <section aria-labelledby="import-counts-{{ $window }}">
                <h3 id="import-counts-{{ $window }}">{{ $block['label'] }}</h3>
                <dl class="detail-list import-counts" data-count-window="{{ $window }}">
                    @foreach (\App\Core\Mail\Inbound\ImportMonitor::COUNT_KEYS as $key)
                        <div>
                            <dt>{{ __("admin.emails.import.monitor.count.{$key}") }}</dt>
                            <dd data-count="{{ $key }}">{{ $block['data'][$key] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach
    </div>

    <section aria-labelledby="import-chart-heading" data-import-chart>
        <h3 id="import-chart-heading">{{ __('admin.emails.import.monitor.chart_heading') }}</h3>
        <p class="muted">{{ __('admin.emails.import.monitor.chart_intro') }}</p>
        <div class="import-chart" role="img" aria-labelledby="import-chart-caption">
            @foreach ($monitor['chart'] as $day)
                <div class="import-chart-day" data-chart-day="{{ $day['day'] }}" title="{{ __('admin.emails.import.monitor.chart_day', [
                    'day' => $day['day'],
                    'posted' => $day['posted'],
                    'held' => $day['held'],
                    'failed' => $day['failed'],
                ]) }}">
                    <div class="import-chart-bars" style="--chart-height: {{ $day['height'] }}%;">
                        @if ($day['posted'] > 0)
                            <span class="import-chart-bar is-posted" style="flex: {{ $day['posted'] }};" data-chart-posted="{{ $day['posted'] }}"></span>
                        @endif
                        @if ($day['held'] > 0)
                            <span class="import-chart-bar is-held" style="flex: {{ $day['held'] }};" data-chart-held="{{ $day['held'] }}"></span>
                        @endif
                        @if ($day['failed'] > 0)
                            <span class="import-chart-bar is-failed" style="flex: {{ $day['failed'] }};" data-chart-failed="{{ $day['failed'] }}"></span>
                        @endif
                    </div>
                    <span class="import-chart-label">{{ \Illuminate\Support\Carbon::parse($day['day'])->format('d M') }}</span>
                </div>
            @endforeach
        </div>
        <table class="import-chart-table">
            <caption id="import-chart-caption">{{ __('admin.emails.import.monitor.chart_caption') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('admin.emails.import.monitor.chart_day_col') }}</th>
                    <th scope="col">{{ __('admin.emails.import.monitor.count.posted') }}</th>
                    <th scope="col">{{ __('admin.emails.import.monitor.count.held') }}</th>
                    <th scope="col">{{ __('admin.emails.import.monitor.count.failed') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($monitor['chart'] as $day)
                    <tr data-chart-row="{{ $day['day'] }}">
                        <th scope="row">{{ $day['day'] }}</th>
                        <td>{{ $day['posted'] }}</td>
                        <td>{{ $day['held'] }}</td>
                        <td>{{ $day['failed'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section aria-labelledby="import-recent-heading" data-import-recent>
        <h3 id="import-recent-heading">{{ __('admin.emails.import.monitor.recent_heading') }}</h3>
        <form method="get" action="{{ route('admin.emails.import.index') }}" class="import-recent-filter">
            <label for="import-recent-status">{{ __('admin.emails.import.monitor.filter_label') }}</label>
            <select id="import-recent-status" name="status">
                <option value="">{{ __('admin.emails.import.monitor.filter_all') }}</option>
                @foreach ($monitorStatuses as $option => $label)
                    <option value="{{ $option }}" @selected($monitor['status_filter'] === $option)>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" class="button button-secondary">{{ __('admin.emails.import.monitor.filter_apply') }}</button>
        </form>

        @if ($monitor['recent'] === [])
            <p data-import-recent-empty>{{ __('admin.emails.import.monitor.recent_empty') }}</p>
        @else
            <div class="table-wrap" role="region" aria-label="{{ __('admin.emails.import.monitor.recent_heading') }}" tabindex="0">
                <table>
                    <caption>{{ __('admin.emails.import.monitor.recent_caption') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.emails.import.monitor.col_when') }}</th>
                            <th scope="col">{{ __('admin.emails.import.monitor.col_sender') }}</th>
                            <th scope="col">{{ __('admin.emails.import.monitor.col_record') }}</th>
                            <th scope="col">{{ __('admin.emails.import.monitor.col_status') }}</th>
                            <th scope="col">{{ __('admin.emails.import.monitor.col_reason') }}</th>
                            <th scope="col">{{ __('admin.emails.import.monitor.col_subject') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($monitor['recent'] as $row)
                            <tr data-import-row="{{ $row['id'] }}" data-import-status="{{ $row['status'] }}">
                                <td>{{ $row['at']?->format('Y-m-d H:i') ?? __('admin.emails.import.never') }}</td>
                                <td>{{ $row['sender'] }}</td>
                                <td>
                                    @if ($row['record_url'] !== null)
                                        <a href="{{ $row['record_url'] }}" data-import-record-link>{{ $row['record'] }}</a>
                                    @else
                                        {{ $row['record'] ?? __('admin.emails.import.never') }}
                                    @endif
                                </td>
                                <td><span class="status">{{ $row['status_label'] }}</span></td>
                                <td>{{ $row['reason'] ?? __('admin.emails.import.never') }}</td>
                                <td data-import-subject>{{ $row['subject'] ?? __('admin.emails.import.never') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</section>
