@extends('layouts.app')

@section('title', __('admin.emails.page_title'))

@section('content')
    @include('admin._navigation')

    <header class="page-heading">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.emails.index') }}">{{ __('admin.emails.heading') }}</a></p>
            <h1>{{ __('admin.emails.log_heading') }}</h1>
            <p>{{ __('admin.emails.log_intro', ['days' => $retentionDays]) }}</p>
        </div>
    </header>

    <div class="panel-stack">
        <section class="panel" aria-labelledby="email-log-heading">
            <h2 id="email-log-heading">{{ __('admin.emails.log_recent') }}</h2>
            @if($entries->isEmpty())
                <p>{{ __('admin.emails.log_empty') }}</p>
            @else
                <div class="table-wrap" role="region" aria-label="{{ __('admin.emails.log_recent') }}" tabindex="0">
                    <table>
                        <caption>{{ __('admin.emails.log_caption') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('admin.emails.log_when') }}</th>
                                <th scope="col">{{ __('admin.emails.name') }}</th>
                                <th scope="col">{{ __('admin.emails.log_recipient') }}</th>
                                <th scope="col">{{ __('admin.emails.log_mailer') }}</th>
                                <th scope="col">{{ __('admin.emails.log_status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($entries as $entry)
                                @php($template = $entry->template_key === null ? null : ($templates[$entry->template_key] ?? null))
                                <tr data-mail-log-entry="{{ $entry->id }}">
                                    <td>{{ $entry->created_at?->format('Y-m-d H:i') }}</td>
                                    <td>{{ $template?->label() ?? __('admin.emails.log_unregistered') }}</td>
                                    <td>
                                        {{ $entry->recipient_domain ?? '—' }}
                                        @if($entry->recipient_hash)
                                            <span class="muted"><code>{{ substr($entry->recipient_hash, 0, 10) }}</code></span>
                                        @endif
                                    </td>
                                    <td>{{ $entry->mailer }}</td>
                                    <td>{{ $entry->isSent() ? __('admin.emails.log_sent') : __('admin.emails.log_failed') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $entries->links() }}
            @endif
        </section>
    </div>
@endsection
