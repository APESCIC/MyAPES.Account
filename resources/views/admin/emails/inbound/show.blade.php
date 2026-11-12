@extends('layouts.app')

@section('title', __('admin.emails.inbound.page_title'))

@section('content')
    <header class="page-heading">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.emails.inbound.index') }}">{{ __('admin.emails.inbound.heading') }}</a></p>
            <h1>{{ __('admin.emails.inbound.detail_heading', ['id' => $email->id]) }}</h1>
        </div>
    </header>

    <div class="panel-stack">
        <section class="panel" aria-labelledby="inbound-detail-heading">
            <h2 id="inbound-detail-heading">{{ __('admin.emails.details') }}</h2>
            <dl class="detail-list">
                <div><dt>{{ __('admin.emails.inbound.received') }}</dt><dd>{{ $email->received_at?->format('Y-m-d H:i') }}</dd></div>
                <div>
                    <dt>{{ __('admin.emails.inbound.from') }}</dt>
                    <dd>
                        {{ $email->user?->name ?? '—' }}
                        @if($email->from_address)<br><small class="muted">{{ $email->from_address }}</small>@elseif($email->from_domain)<br><small class="muted">{{ $email->from_domain }}</small>@endif
                    </dd>
                </div>
                <div><dt>{{ __('admin.emails.inbound.detail_subject') }}</dt><dd>{{ $email->subject ?? '—' }}</dd></div>
                <div>
                    <dt>{{ __('admin.emails.inbound.record') }}</dt>
                    <dd>
                        {{ $email->record_prefix !== null ? $email->record_prefix.'-'.$email->record_id : '—' }}
                        @if($recordUrl)<br><a href="{{ $recordUrl }}">{{ __('admin.emails.inbound.detail_open_record') }}</a>@endif
                    </dd>
                </div>
                <div><dt>{{ __('admin.emails.inbound.status') }}</dt><dd><span class="status" data-inbound-status>{{ $email->statusLabel() }}</span></dd></div>
                <div><dt>{{ __('admin.emails.inbound.reason') }}</dt><dd>{{ $email->reasonLabel() ?? '—' }}</dd></div>
                <div>
                    <dt>{{ __('admin.emails.inbound.detail_auth') }}</dt>
                    <dd>{{ __('admin.emails.inbound.auth', [
                        'spf' => $email->spf ?? __('admin.emails.inbound.auth_unknown'),
                        'dkim' => $email->dkim ?? __('admin.emails.inbound.auth_unknown'),
                        'dmarc' => $email->dmarc ?? __('admin.emails.inbound.auth_unknown'),
                    ]) }}</dd>
                </div>
                <div><dt>{{ __('admin.emails.inbound.detail_attachments') }}</dt><dd>{{ __('admin.emails.inbound.attachments', ['stored' => $email->attachments_stored, 'rejected' => $email->attachments_rejected]) }}</dd></div>
                @if($email->detail)
                    <div><dt>{{ __('admin.emails.inbound.detail_detail') }}</dt><dd>{{ $email->detail }}</dd></div>
                @endif
                <div><dt>{{ __('admin.emails.inbound.detail_attempts') }}</dt><dd>{{ $email->attempts }}</dd></div>
                @if($email->raw_pruned_at)
                    <div><dt>{{ __('admin.emails.inbound.detail_message') }}</dt><dd class="muted">{{ __('admin.emails.inbound.pruned', ['days' => $retentionDays]) }}</dd></div>
                @endif
            </dl>
        </section>

        @can('admin.emails.manage')
            @if($email->isActionable())
                <section class="panel" aria-labelledby="inbound-actions-heading" data-inbound-actions>
                    <h2 id="inbound-actions-heading">{{ __('admin.emails.inbound.actions') }}</h2>
                    @if($canAttach)
                        <form method="post" action="{{ route('admin.emails.inbound.attach', $email) }}" class="stacked-form" data-inbound-attach>
                            @csrf
                            <h3>{{ __('admin.emails.inbound.attach') }}</h3>
                            <p class="muted">{{ __('admin.emails.inbound.attach_help') }}</p>
                            <label for="attach-record">{{ __('admin.emails.inbound.attach_record') }}</label>
                            <input id="attach-record" name="record" type="text" required maxlength="20" pattern="[A-Za-z][0-9]+" value="{{ old('record', $email->record_prefix !== null ? $email->record_prefix.$email->record_id : '') }}" placeholder="{{ __('admin.emails.inbound.attach_record_placeholder') }}">
                            <label for="attach-account">{{ __('admin.emails.inbound.attach_account') }}</label>
                            <input id="attach-account" name="account" type="email" maxlength="255" value="{{ old('account', $email->user?->email) }}" @if($email->user === null) required @endif>
                            <p class="muted">{{ $email->user !== null ? __('admin.emails.inbound.attach_account_help') : __('admin.emails.inbound.attach_account_required') }}</p>
                            <div class="actions">
                                <button type="submit">{{ __('admin.emails.inbound.attach_submit') }}</button>
                            </div>
                        </form>
                    @endif
                    <form method="post" action="{{ route('admin.emails.inbound.retry', $email) }}">
                        @csrf
                        <p class="muted">{{ __('admin.emails.inbound.retry_help') }}</p>
                        <div class="actions">
                            <button type="submit">{{ __('admin.emails.inbound.retry') }}</button>
                        </div>
                    </form>
                    <form method="post" action="{{ route('admin.emails.inbound.discard', $email) }}">
                        @csrf
                        <p class="muted">{{ __('admin.emails.inbound.discard_help') }}</p>
                        <label class="inline-check">
                            <input type="checkbox" name="confirm_discard" value="1" required>
                            <span>{{ __('admin.emails.inbound.discard_confirm') }}</span>
                        </label>
                        <div class="actions">
                            <button type="submit" class="button button-secondary">{{ __('admin.emails.inbound.discard') }}</button>
                        </div>
                    </form>
                </section>
            @endif
        @endcan
    </div>
@endsection
