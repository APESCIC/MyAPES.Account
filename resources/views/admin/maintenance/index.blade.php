@extends('layouts.app')

@section('title', 'Admin maintenance | MyAPES Core')

@section('content')
    @include('admin._navigation')

    <header class="page-heading">
        <div>
            <p class="eyebrow">{{ __('admin.maintenance.guarded_recovery_controls') }}</p>
            <h1>{{ __('admin.maintenance.admin_maintenance') }}</h1>
            <p>{{ __('admin.maintenance.manage_laravel_maintenance_mode_without_creating_a_secret_by') }}</p>
        </div>
    </header>

    <div class="panel-stack">
        <section class="panel" aria-labelledby="maintenance-state-heading">
            <h2 id="maintenance-state-heading">{{ $active ? 'Application is in maintenance' : 'Application is available' }}</h2>
            <p>{{ __('admin.maintenance.laravel_s_native_maintenance_store_is_authoritative') }}</p>
            @if($current)
                <dl class="detail-list">
                    <div><dt>{{ __('admin.maintenance.state') }}</dt><dd>{{ str($current->state)->replace('_', ' ')->title() }}</dd></div>
                    <div><dt>{{ __('admin.maintenance.message') }}</dt><dd class="maintenance-message">{{ $current->message }}</dd></div>
                    <div><dt>{{ __('admin.maintenance.started') }}</dt><dd>{{ $current->activated_at?->format('Y-m-d H:i T') ?? 'Pending reconciliation' }}</dd></div>
                    <div><dt>{{ __('admin.maintenance.planned_end') }}</dt><dd>{{ $current->planned_end_at?->format('Y-m-d H:i T') ?? 'Not specified' }}</dd></div>
                    <div><dt>{{ __('admin.maintenance.initiated_by') }}</dt><dd>{{ $current->initiator?->name ?? 'System / CLI' }}</dd></div>
                    @if($current->deactivationRequester)
                        <div><dt>{{ __('admin.maintenance.end_requested_by') }}</dt><dd>{{ $current->deactivationRequester->name }}</dd></div>
                    @endif
                </dl>
            @endif
            @if($problem)
                <p class="error-list">{{ $problem }}</p>
            @endif
        </section>

        <section class="panel" aria-labelledby="queue-impact-heading">
            <h2 id="queue-impact-heading">{{ __('admin.maintenance.queue_processing_pauses') }}</h2>
            <p>The Redis queue worker runs without <code>--force</code>. Jobs remain durable and resume after maintenance ends.</p>
        </section>

        @unless($problem)
            @unless($active)
                <section class="panel" aria-labelledby="activate-maintenance-heading">
                    <h2 id="activate-maintenance-heading">{{ __('admin.maintenance.activate_maintenance') }}</h2>
                    <p>{{ __('admin.maintenance.public_users_and_ordinary_staff_will_receive_the_maintenance') }}</p>
                    <form method="post" action="{{ route('admin.maintenance.activate') }}" class="stacked-form">
                        @csrf
                        <label for="maintenance-message">{{ __('admin.maintenance.public_message') }}</label>
                        <textarea id="maintenance-message" name="message" maxlength="500" required>{{ old('message') }}</textarea>
                        <label for="maintenance-planned-end">{{ __('admin.maintenance.planned_end_optional') }}</label>
                        <input id="maintenance-planned-end" type="datetime-local" name="planned_end_at" value="{{ old('planned_end_at') }}">
                        <label>
                            <input type="checkbox" name="confirm_activation" value="1" required>
                            {{ __('admin.maintenance.confirm_block') }}
                        </label>
                        <button type="submit" class="button danger-btn">{{ __('admin.maintenance.activate_maintenance') }}</button>
                    </form>
                </section>
            @else
                <section class="panel" aria-labelledby="deactivate-maintenance-heading">
                    <h2 id="deactivate-maintenance-heading">{{ __('admin.maintenance.end_maintenance') }}</h2>
                    <p>{{ __('admin.maintenance.public_and_staff_traffic_will_resume_immediately_queued_jobs') }}</p>
                    <form method="post" action="{{ route('admin.maintenance.deactivate') }}" class="stacked-form">
                        @csrf
                        <label>
                            <input type="checkbox" name="confirm_deactivation" value="1" required>
                            {{ __('admin.maintenance.confirm_resume') }}
                        </label>
                        <button type="submit" class="button">{{ __('admin.maintenance.deactivate_maintenance') }}</button>
                    </form>
                </section>
            @endunless
        @endunless

        <section class="panel" aria-labelledby="maintenance-history-heading">
            <h2 id="maintenance-history-heading">{{ __('admin.maintenance.recent_maintenance_windows') }}</h2>
            @if($history->isEmpty())
                <p>{{ __('admin.maintenance.no_maintenance_windows_have_been_recorded') }}</p>
            @else
                <div class="table-wrap" role="region" aria-label="{{ __('admin.maintenance.recent_maintenance_history') }}" tabindex="0">
                    <table>
                        <caption>{{ __('admin.maintenance.the_25_most_recent_maintenance_windows') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('admin.maintenance.state') }}</th>
                                <th scope="col">{{ __('admin.maintenance.message') }}</th>
                                <th scope="col">{{ __('admin.maintenance.started') }}</th>
                                <th scope="col">{{ __('admin.maintenance.initiated_by') }}</th>
                                <th scope="col">{{ __('admin.maintenance.ended') }}</th>
                                <th scope="col">{{ __('admin.maintenance.ended_by') }}</th>
                                <th scope="col">{{ __('admin.maintenance.failure') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($history as $window)
                                <tr>
                                    <td>{{ str($window->state)->replace('_', ' ')->title() }}</td>
                                    <td class="maintenance-message">{{ $window->message }}</td>
                                    <td>{{ $window->activated_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                    <td>{{ $window->initiator?->name ?? 'System / CLI' }}</td>
                                    <td>{{ $window->deactivated_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                    <td>{{ $window->endingActor?->name ?? '—' }}</td>
                                    <td>{{ $window->failure_summary ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
