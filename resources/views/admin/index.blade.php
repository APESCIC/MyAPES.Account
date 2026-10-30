@extends('layouts.app')

@section('title', 'Admin | MyAPES Account')

@can('superadmin.access')
    @push('head')
        @vite('resources/js/admin-analytics.js')
    @endpush
@endcan

@section('content')
    @php
        $accounts = $dashboard['accounts'];
        $workload = $dashboard['workload'];
        $median = $workload['median_closure_minutes'];
        $identityLabels = [
            'local' => __('admin.overview.identity.local'),
            'cloudron_oidc' => __('admin.overview.identity.cloudron_oidc'),
            'hybrid' => __('admin.overview.identity.hybrid'),
        ];
        $accessLabels = [
            'service-user' => __('admin.overview.access.public'),
            'staff' => __('admin.overview.access.staff'),
            'administrator' => __('admin.overview.access.administrator'),
            'super-admin' => __('admin.overview.access.super_admin'),
        ];
        $alertLabels = [
            'disabled' => __('admin.overview.alert.disabled'),
            'incompatible' => __('admin.overview.alert.incompatible'),
            'code_not_shipped' => __('admin.overview.alert.code_not_shipped'),
            'active-records' => __('admin.overview.alert.active_records'),
        ];
        $chartData = [
            'days' => $workload['days'],
            'created' => $workload['created_per_day'],
            'closed' => $workload['closed_per_day'],
            'instances' => $workload['by_instance'],
        ];
        $showTechnicalExtras = auth()->user()?->can('superadmin.access') === true;
    @endphp

    <div class="panel">
        <h1>{{ __('admin.overview.admin_overview') }}</h1>
        <p class="muted">
            @if($showTechnicalExtras)
                {{ __('admin.overview.intro_privileged') }}
            @else
                {{ __('admin.overview.intro_standard') }}
            @endif
        </p>

        <form method="get" action="{{ route('admin.index') }}" class="analytics-range" aria-label="{{ __('admin.overview.reporting_range') }}">
            <fieldset>
                <legend>{{ __('admin.overview.reporting_range_legend') }}</legend>
                @foreach($ranges as $option)
                    <label>
                        <input type="radio" name="range" value="{{ $option }}" @checked($range === $option) onchange="this.form.submit()">
                        Last {{ $option }} days
                    </label>
                @endforeach
                <button type="submit">{{ __('admin.overview.update_range') }}</button>
            </fieldset>
        </form>

        <div class="grid analytics-kpis" role="list">
            <div class="panel panel-flat" role="listitem">
                <h3>{{ __('admin.overview.total_accounts') }}</h3>
                <div data-kpi="total-accounts">{{ $accounts['total'] }}</div>
            </div>
            <div class="panel panel-flat" role="listitem">
                <h3>{{ __('admin.overview.created_in_range') }}</h3>
                <div data-kpi="created-in-range">{{ $accounts['created_in_range'] }}</div>
            </div>
            <div class="panel panel-flat" role="listitem">
                <h3>{{ __('admin.overview.suspended') }}</h3>
                <div data-kpi="suspended-accounts">{{ $accounts['suspended'] }}</div>
            </div>
            <div class="panel panel-flat" role="listitem">
                <h3>{{ __('admin.overview.open_workload') }}</h3>
                <div data-kpi="open-workload">{{ $workload['open'] }}</div>
            </div>
            <div class="panel panel-flat" role="listitem">
                <h3>{{ __('admin.overview.high_or_urgent') }}</h3>
                <div data-kpi="high-or-urgent">{{ $workload['high_or_urgent'] }}</div>
            </div>
            <div class="panel panel-flat" role="listitem">
                <h3>{{ __('admin.overview.unassigned') }}</h3>
                <div data-kpi="unassigned">{{ $workload['unassigned'] }}</div>
            </div>
            @if($showTechnicalExtras)
                <div class="panel panel-flat" role="listitem">
                    <h3>{{ __('admin.overview.enabled_plugins') }}</h3>
                    <div data-kpi="enabled-modules">{{ $dashboard['modules']['enabled'] }} / {{ $dashboard['modules']['installed'] }}</div>
                </div>
                <div class="panel panel-flat" role="listitem">
                    <h3>{{ __('admin.overview.median_closure') }}</h3>
                    <div data-kpi="median-closure">
                        @if($median === null)
                            not available
                        @else
                            {{ number_format($median, 1) }} minutes
                        @endif
                    </div>
                </div>
                <div class="panel panel-flat" role="listitem">
                    <h3>{{ __('admin.overview.plugin_alerts') }}</h3>
                    <div data-kpi="module-alerts">{{ count($dashboard['module_alerts']) }}</div>
                </div>
            @endif
        </div>
    </div>

    <div class="panel">
        <h2>{{ __('admin.overview.accounts_by_identity_and_access_class') }}</h2>
        <div class="grid">
            <table data-table="identity-types">
                <caption>{{ __('admin.overview.account_totals_by_identity_type') }}</caption>
                <thead><tr><th scope="col">{{ __('admin.overview.identity_type') }}</th><th scope="col">{{ __('admin.overview.accounts') }}</th></tr></thead>
                <tbody>
                @foreach($accounts['by_identity_type'] as $type => $count)
                    <tr>
                        <th scope="row">{{ $identityLabels[$type] ?? $type }}</th>
                        <td>{{ $count }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <table data-table="access-classes">
                <caption>{{ __('admin.overview.account_totals_by_protected_access_class') }}</caption>
                <thead><tr><th scope="col">{{ __('admin.overview.access_class') }}</th><th scope="col">{{ __('admin.overview.accounts') }}</th></tr></thead>
                <tbody>
                @foreach($accounts['by_access_class'] as $class => $count)
                    <tr>
                        <th scope="row">{{ $accessLabels[$class] ?? $class }}</th>
                        <td>{{ $count }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @can('admin.users.view')
        <div class="panel">
            <h2>{{ __('admin.overview.recent_accounts') }}</h2>
            <table>
                <thead><tr><th>{{ __('admin.access.name') }}</th><th>{{ __('admin.access.email') }}</th><th>{{ __('admin.access.role') }}</th><th>{{ __('admin.overview.created') }}</th></tr></thead>
                <tbody>
                @foreach($recentUsers as $user)
                    <tr>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td>{{ $authorizationProfile->displayKey($user) }}</td>
                        <td>{{ $user->created_at }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="actions">
                <a href="{{ route('admin.users.index', ['account_type' => 'public']) }}">{{ __('admin.overview.manage_public_users') }}</a>
                ·
                <a href="{{ route('admin.users.index', ['account_type' => 'staff']) }}">{{ __('admin.overview.manage_staff') }}</a>
            </p>
        </div>
    @endcan

    @if($showTechnicalExtras)
        <div class="panel">
            <h2>{{ __('admin.overview.created_versus_closed') }}</h2>
            <p class="muted">{{ __('admin.overview.daily_created_and_closed_items_for_the_selected_range_patter') }}</p>
            <div class="analytics-chart-frame" data-chart-frame="trend">
                <canvas id="analytics-trend-chart" role="img" aria-labelledby="analytics-trend-caption"></canvas>
            </div>
            <table id="analytics-trend-table" data-table="created-versus-closed">
                <caption id="analytics-trend-caption">{{ __('admin.overview.created_versus_closed_items_per_day') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.overview.day') }}</th>
                        <th scope="col">{{ __('admin.overview.created') }}</th>
                        <th scope="col">{{ __('admin.overview.closed') }}</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($workload['days'] as $index => $day)
                    <tr>
                        <th scope="row">{{ $day }}</th>
                        <td>{{ $workload['created_per_day'][$index] }}</td>
                        <td>{{ $workload['closed_per_day'][$index] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="panel">
            <h2>{{ __('admin.overview.open_workload_by_service') }}</h2>
            <p class="muted">{{ __('admin.overview.currently_open_tickets_cases_and_consultations_by_installed_') }}</p>
            <div class="analytics-chart-frame" data-chart-frame="workload">
                <canvas id="analytics-workload-chart" role="img" aria-labelledby="analytics-workload-caption"></canvas>
            </div>
            <table id="analytics-workload-table" data-table="workload-by-service">
                <caption id="analytics-workload-caption">{{ __('admin.overview.open_workload_by_service_and_plugin') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.overview.service') }}</th>
                        <th scope="col">{{ __('admin.overview.open') }}</th>
                        <th scope="col">{{ __('admin.overview.high_or_urgent') }}</th>
                        <th scope="col">{{ __('admin.overview.unassigned') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($workload['by_instance'] as $instance)
                    <tr data-instance="{{ $instance['key'] }}">
                        <th scope="row">{{ $instance['sub_core'] }} — {{ $instance['module'] }}</th>
                        <td>{{ $instance['open'] }}</td>
                        <td>{{ $instance['high_or_urgent'] }}</td>
                        <td>{{ $instance['unassigned'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">{{ __('admin.overview.no_plugin_analytics_are_available') }}</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="panel">
            <h2>{{ __('admin.overview.operational_context') }}</h2>
            <p data-maintenance-state="{{ $dashboard['maintenance']['active'] ? 'active' : 'inactive' }}">
                Maintenance is
                <strong>{{ $dashboard['maintenance']['active'] ? 'active' : 'inactive' }}</strong>
                @if($dashboard['maintenance']['message'])
                    — {{ $dashboard['maintenance']['message'] }}
                @endif
            </p>
            <table data-table="module-alerts">
                <caption>{{ __('admin.overview.disabled_incompatible_code_not_shipped_or_active_record_plug') }}</caption>
                <thead><tr><th scope="col">{{ __('admin.overview.plugin') }}</th><th scope="col">{{ __('admin.access.status') }}</th></tr></thead>
                <tbody>
                @forelse($dashboard['module_alerts'] as $alert)
                    <tr data-alert-kind="{{ $alert['kind'] }}">
                        <th scope="row">{{ $alert['label'] }}</th>
                        <td>{{ $alertLabels[$alert['kind']] ?? $alert['kind'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2">{{ __('admin.overview.no_plugin_warnings') }}</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
            <table data-table="privileged-events">
                <caption>{{ __('admin.overview.recent_privileged_audit_events') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.access.action') }}</th>
                        <th scope="col">{{ __('admin.overview.actor') }}</th>
                        <th scope="col">{{ __('admin.overview.time') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($dashboard['privileged_events'] as $event)
                    <tr>
                        <th scope="row">{{ $event['event'] }}</th>
                        <td>{{ $event['actor'] }}</td>
                        <td>{{ $event['occurred_at'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">{{ __('admin.overview.no_privileged_events_in_the_audit_log') }}</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <script type="application/json" id="admin-analytics-chart-data">@json($chartData)</script>
    @endif
@endsection
