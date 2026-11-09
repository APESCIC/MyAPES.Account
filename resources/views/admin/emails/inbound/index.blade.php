@extends('layouts.app')

@section('title', __('admin.emails.inbound.page_title'))

@section('content')
    <header class="page-heading">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.emails.index') }}">{{ __('admin.emails.heading') }}</a></p>
            <h1>{{ __('admin.emails.inbound.heading') }}</h1>
            <p>{{ __('admin.emails.inbound.intro', ['address' => $address, 'days' => $retentionDays]) }}</p>
            <p class="muted" data-inbound-state>
                @if(! $repliesEnabled)
                    {{ __('admin.emails.inbound.status_off') }}
                @elseif(! $pollingConfigured)
                    {{ __('admin.emails.inbound.status_no_mailbox') }}
                @else
                    {{ __('admin.emails.inbound.status_on') }}
                @endif
            </p>
        </div>
    </header>

    @include('admin.emails._tabs', ['activeTab' => 'inbound'])

    <div class="panel-stack">
        <section class="panel" aria-labelledby="inbound-list-heading">
            <h2 id="inbound-list-heading">{{ __('admin.emails.inbound.caption') }}</h2>
            <form method="get" action="{{ route('admin.emails.inbound.index') }}">
                <label for="inbound-status">{{ __('admin.emails.inbound.filter_label') }}</label>
                <select id="inbound-status" name="status">
                    <option value="">{{ __('admin.emails.inbound.filter_all') }}</option>
                    @foreach($statuses as $option)
                        <option value="{{ $option }}" @selected($status === $option)>{{ __('admin.emails.inbound.statuses.'.$option) }}</option>
                    @endforeach
                </select>
                <button type="submit" class="button button-secondary">{{ __('admin.emails.inbound.filter_apply') }}</button>
            </form>

            @if($emails->isEmpty())
                <p>{{ __('admin.emails.inbound.empty') }}</p>
            @else
                <div class="table-wrap" role="region" aria-label="{{ __('admin.emails.inbound.caption') }}" tabindex="0">
                    <table>
                        <caption>{{ __('admin.emails.inbound.caption') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('admin.emails.inbound.received') }}</th>
                                <th scope="col">{{ __('admin.emails.inbound.from') }}</th>
                                <th scope="col">{{ __('admin.emails.inbound.record') }}</th>
                                <th scope="col">{{ __('admin.emails.inbound.status') }}</th>
                                <th scope="col">{{ __('admin.emails.inbound.reason') }}</th>
                                <th scope="col">{{ __('admin.emails.inbound.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($emails as $email)
                                <tr data-inbound-email="{{ $email->id }}">
                                    <td>{{ $email->received_at?->format('Y-m-d H:i') }}</td>
                                    <td>
                                        {{ $email->user?->name ?? ($email->from_address ?? $email->from_domain ?? '—') }}
                                        @if($email->user && $email->from_address)
                                            <br><small class="muted">{{ $email->from_address }}</small>
                                        @endif
                                    </td>
                                    <td>{{ $email->record_prefix !== null ? $email->record_prefix.'-'.$email->record_id : '—' }}</td>
                                    <td><span class="status">{{ __('admin.emails.inbound.statuses.'.$email->status) }}</span></td>
                                    <td>{{ $email->reasonLabel() ?? '—' }}</td>
                                    <td><a href="{{ route('admin.emails.inbound.show', $email) }}">{{ __('admin.emails.inbound.view') }}</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $emails->links() }}
            @endif
        </section>

        <section class="panel" aria-labelledby="inbound-services-heading" data-inbound-services>
            <h2 id="inbound-services-heading">{{ __('admin.emails.inbound.toggles_heading') }}</h2>
            <p class="muted">{{ __('admin.emails.inbound.toggles_help') }}</p>
            @can('admin.emails.manage')
                <form method="post" action="{{ route('admin.emails.inbound.services.update') }}" class="stacked-form">
                    @csrf
                    @method('PUT')
                    @foreach($services as $key => $service)
                        <input type="hidden" name="services[{{ $key }}]" value="0">
                        <label class="inline-check">
                            <input type="checkbox" name="services[{{ $key }}]" value="1" @checked($service['enabled'])>
                            <span>{{ $service['label'] }}</span>
                        </label>
                    @endforeach
                    <div class="actions">
                        <button type="submit">{{ __('admin.emails.inbound.toggle_save') }}</button>
                    </div>
                </form>
            @else
                <dl class="detail-list">
                    @foreach($services as $service)
                        <div><dt>{{ $service['label'] }}</dt><dd>{{ $service['enabled'] ? __('admin.emails.inbound.toggle_on') : __('admin.emails.inbound.toggle_off') }}</dd></div>
                    @endforeach
                </dl>
            @endcan
        </section>
    </div>
@endsection
