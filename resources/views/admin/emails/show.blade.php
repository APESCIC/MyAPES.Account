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

        @if($template->hasEditableCopy())
            <section class="panel" aria-labelledby="email-template-copy-heading" data-mail-copy>
                <h2 id="email-template-copy-heading">{{ __('admin.emails.copy_heading') }}</h2>
                <p class="muted">{{ __('admin.emails.copy_help') }}</p>
                <dl class="detail-list">
                    @if($shippedSubject !== null)
                        <div><dt>{{ __('admin.emails.copy_shipped_subject') }}</dt><dd>{{ $shippedSubject }}</dd></div>
                    @endif
                    @if($shippedIntro !== null)
                        <div><dt>{{ __('admin.emails.copy_shipped_intro') }}</dt><dd>{{ $shippedIntro }}</dd></div>
                    @endif
                    <div>
                        <dt>{{ __('admin.emails.copy_status') }}</dt>
                        <dd>
                            @if($override)
                                {{ __('admin.emails.copy_overridden', ['name' => $override->editor?->name ?? __('admin.emails.copy_unknown_editor'), 'when' => $override->updated_at?->format('Y-m-d H:i')]) }}
                            @else
                                {{ __('admin.emails.copy_shipped') }}
                            @endif
                        </dd>
                    </div>
                </dl>

                @can('admin.emails.manage')
                    <form method="post" action="{{ route('admin.emails.copy.update', $template->key) }}" class="stacked-form">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="lock_version" value="{{ $override?->lock_version ?? 0 }}">
                        @if($template->subjectKey !== null)
                            <label for="mail-copy-subject">{{ __('admin.emails.copy_subject') }}</label>
                            <input id="mail-copy-subject" name="subject" maxlength="200" value="{{ old('subject', $override?->subject) }}" placeholder="{{ $shippedSubject }}">
                        @endif
                        @if($template->introKey !== null)
                            <label for="mail-copy-intro">{{ __('admin.emails.copy_intro') }}</label>
                            <textarea id="mail-copy-intro" name="intro" maxlength="1000" placeholder="{{ $shippedIntro }}">{{ old('intro', $override?->intro) }}</textarea>
                        @endif
                        <div class="actions">
                            <button type="submit">{{ __('admin.emails.copy_save') }}</button>
                        </div>
                    </form>
                    @if($override)
                        <form method="post" action="{{ route('admin.emails.copy.reset', $template->key) }}">
                            @csrf
                            @method('DELETE')
                            <label class="inline-check">
                                <input type="checkbox" name="confirm_reset" value="1" required>
                                <span>{{ __('admin.emails.copy_reset_confirm') }}</span>
                            </label>
                            <div class="actions">
                                <button type="submit" class="button button-secondary">{{ __('admin.emails.copy_reset') }}</button>
                            </div>
                        </form>
                    @endif
                @endcan
            </section>
        @endif

        @can('admin.emails.manage')
            <section class="panel" aria-labelledby="email-template-test-heading">
                <h2 id="email-template-test-heading">{{ __('admin.emails.send_test') }}</h2>
                <p class="muted">{{ __('admin.emails.send_test_help', ['email' => auth()->user()->email, 'count' => $testSendLimit]) }}</p>
                <form method="post" action="{{ route('admin.emails.test', $template->key) }}">
                    @csrf
                    <div class="actions">
                        <button type="submit">{{ __('admin.emails.send_test_button') }}</button>
                    </div>
                </form>
            </section>
        @endcan

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
