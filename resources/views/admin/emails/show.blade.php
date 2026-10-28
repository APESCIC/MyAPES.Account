@extends('layouts.app')

@section('title', __('admin.emails.page_title'))

@section('content')
    @include('admin._navigation')

    <header class="page-heading">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.emails.index') }}">{{ __('admin.emails.heading') }}</a></p>
            <h1>{{ $template->label() }}</h1>
            <p>{{ $template->description() }}</p>
        </div>
    </header>

    <div class="panel-stack">
        <section class="panel" aria-labelledby="email-template-details-heading">
            <h2 id="email-template-details-heading">{{ __('admin.emails.details') }}</h2>
            <dl class="detail-list">
                <div><dt>{{ __('admin.emails.key') }}</dt><dd><code>{{ $template->key }}</code></dd></div>
                <div><dt>{{ __('admin.emails.category') }}</dt><dd>{{ $template->category->label() }}</dd></div>
                <div>
                    <dt>{{ __('admin.emails.preference') }}</dt>
                    <dd>{{ $template->category->isMandatory() ? __('admin.emails.always_sent') : __('admin.emails.respects_preference') }}</dd>
                </div>
                <div><dt>{{ __('admin.emails.owner') }}</dt><dd>{{ $template->ownerLabel() }}</dd></div>
                <div><dt>{{ __('admin.emails.sample_subject') }}</dt><dd data-mail-sample-subject>{{ $subject }}</dd></div>
            </dl>
        </section>

        <section class="panel" aria-labelledby="email-template-preview-heading">
            <h2 id="email-template-preview-heading">{{ __('admin.emails.preview') }}</h2>
            <p class="muted">{{ __('admin.emails.preview_help') }}</p>
            <iframe
                class="mail-preview-frame"
                src="{{ route('admin.emails.preview', $template->key) }}"
                title="{{ __('admin.emails.preview_frame_title', ['name' => $template->label()]) }}"
                sandbox
                loading="lazy"
            ></iframe>
        </section>
    </div>
@endsection
