@extends('layouts.app')

@section('title', 'Admin maintenance | MyAPES Account')

@section('content')
    <header class="page-heading">
        <div>
            <p class="eyebrow">{{ __('admin.maintenance.guarded_recovery_controls') }}</p>
            <h1>{{ __('admin.maintenance.admin_maintenance') }}</h1>
            <p>{{ __('admin.maintenance.manage_laravel_maintenance_mode_without_creating_a_secret_by') }}</p>
        </div>
    </header>

    <div class="panel-stack">
        <x-card aria-labelledby="maintenance-state-heading">
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
                <x-alert tone="error">{{ $problem }}</x-alert>
            @endif
        </x-card>

        <x-card aria-labelledby="queue-impact-heading">
            <h2 id="queue-impact-heading">{{ __('admin.maintenance.queue_processing_pauses') }}</h2>
            <p>The Redis queue worker runs without <code>--force</code>. Jobs remain durable and resume after maintenance ends.</p>
        </x-card>

        @unless($problem)
            @unless($active)
                <x-card aria-labelledby="activate-maintenance-heading">
                    <h2 id="activate-maintenance-heading">{{ __('admin.maintenance.activate_maintenance') }}</h2>
                    <p>{{ __('admin.maintenance.public_users_and_ordinary_staff_will_receive_the_maintenance') }}</p>
                    <form method="post" action="{{ route('admin.maintenance.activate') }}" class="stacked-form stack">
                        @csrf
                        <x-field id="maintenance-message" name="message" type="textarea" :label="__('admin.maintenance.public_message')" maxlength="500" required />
                        <x-field id="maintenance-planned-end" name="planned_end_at" type="datetime-local" :label="__('admin.maintenance.planned_end_optional')" />
                        <label class="inline-check">
                            <input type="checkbox" name="confirm_activation" value="1" required>
                            {{ __('admin.maintenance.confirm_block') }}
                        </label>
                        <div>
                            <x-button variant="danger" icon="circle-pause">{{ __('admin.maintenance.activate_maintenance') }}</x-button>
                        </div>
                    </form>
                </x-card>
            @else
                <x-card aria-labelledby="deactivate-maintenance-heading">
                    <h2 id="deactivate-maintenance-heading">{{ __('admin.maintenance.end_maintenance') }}</h2>
                    <p>{{ __('admin.maintenance.public_and_staff_traffic_will_resume_immediately_queued_jobs') }}</p>
                    <form method="post" action="{{ route('admin.maintenance.deactivate') }}" class="stacked-form stack">
                        @csrf
                        <label class="inline-check">
                            <input type="checkbox" name="confirm_deactivation" value="1" required>
                            {{ __('admin.maintenance.confirm_resume') }}
                        </label>
                        <div>
                            <x-button>{{ __('admin.maintenance.deactivate_maintenance') }}</x-button>
                        </div>
                    </form>
                </x-card>
            @endunless
        @endunless

        <x-card aria-labelledby="maintenance-history-heading">
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
        </x-card>
    </div>
@endsection
