<section class="panel" aria-labelledby="hub-records-title" data-hub-records>
    <h2 id="hub-records-title">{{ __('admin.users.hub.records.heading') }}</h2>

    @foreach([
        'tickets' => __('admin.users.hub.records.tickets'),
        'pets' => __('admin.users.hub.records.pets'),
        'cases' => __('admin.users.hub.records.cases'),
        'consultations' => __('admin.users.hub.records.consultations'),
        'applications' => __('admin.users.hub.records.applications'),
    ] as $key => $label)
        <div id="hub-records-{{ $key }}" class="stack-spaced">
            <h3>{{ $label }}</h3>
            @php($page = $hubRecords[$key] ?? null)
            @if($page === null)
                <p class="muted">{{ __('admin.users.hub.records.hidden') }}</p>
            @elseif($page->total() === 0)
                <p class="muted">{{ __('admin.users.hub.records.empty') }}</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.users.hub.records.reference') }}</th>
                            <th scope="col">{{ __('admin.users.hub.records.status') }}</th>
                            <th scope="col">{{ __('admin.users.hub.records.date') }}</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($page as $row)
                        <tr>
                            <td>{{ $row['reference'] }} — {{ $row['label'] }}</td>
                            <td><span class="status">{{ $row['status'] }}</span></td>
                            <td>{{ $row['date'] }}</td>
                            <td><a href="{{ $row['url'] }}">{{ __('admin.users.hub.records.open') }}</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                {{ $page->appends(request()->except('records_page'))->links() }}
            @endif
        </div>
    @endforeach
</section>
