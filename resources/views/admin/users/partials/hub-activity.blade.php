@if($hubActivity)
<section class="panel" aria-labelledby="hub-activity-title" data-hub-activity>
    <h2 id="hub-activity-title">{{ __('admin.users.hub.activity.heading') }}</h2>
    <p class="muted">{{ __('admin.users.hub.activity.intro') }}</p>
    <table>
        <caption>{{ __('admin.users.hub.activity.heading') }}</caption>
        <thead>
            <tr>
                <th scope="col">{{ __('admin.overview.time') }}</th>
                <th scope="col">{{ __('admin.users.event') }}</th>
                <th scope="col">{{ __('admin.users.hub.activity.source') }}</th>
                <th scope="col">{{ __('admin.users.actor_id') }}</th>
                <th scope="col">{{ __('admin.users.safe_context') }}</th>
            </tr>
        </thead>
        <tbody>
        @forelse($hubActivity as $audit)
            <tr>
                <td>{{ $audit['created_at'] }}</td>
                <td><code>{{ $audit['event'] }}</code></td>
                <td>{{ $audit['source'] }}</td>
                <td>{{ $audit['actor_id'] ?? __('admin.users.system') }}</td>
                <td>
                    @forelse($audit['context'] as $key => $value)
                        <div><code>{{ $key }}</code>: {{ is_array($value) ? implode(', ', $value) : $value }}</div>
                    @empty
                        {{ __('admin.users.no_displayable_context') }}
                    @endforelse
                </td>
            </tr>
        @empty
            <tr><td colspan="5">{{ __('admin.users.no_audit_history_is_recorded_for_this_user') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $hubActivity->appends(request()->except('activity_page'))->links() }}
</section>
@endif
