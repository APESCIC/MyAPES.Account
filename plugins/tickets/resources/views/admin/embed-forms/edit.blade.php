@extends('layouts.app')

@section('title', $form ? __('tickets::embed.admin.edit') : __('tickets::embed.admin.create'))

@section('content')
    <div class="sg-page">
        <header class="sg-page__header">
            <h1>{{ $form ? __('tickets::embed.admin.edit') : __('tickets::embed.admin.create') }}</h1>
            <p><a href="{{ route('admin.tickets.embed-forms.index') }}">{{ __('tickets::embed.admin.index_heading') }}</a></p>
        </header>

        @if (session('status'))
            <p class="alert alert-success" role="status">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="post" action="{{ $form ? route('admin.tickets.embed-forms.update', $form) : route('admin.tickets.embed-forms.store') }}">
            @csrf
            @if ($form) @method('PUT') @endif

            <p>
                <label for="name">{{ __('tickets::embed.admin.name') }}</label>
                <input id="name" name="name" type="text" required maxlength="120" value="{{ old('name', $form->name ?? '') }}">
            </p>

            <p>
                <label>
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $form->is_active ?? true))>
                    {{ __('tickets::embed.admin.active') }}
                </label>
            </p>

            <fieldset>
                <legend>{{ __('tickets::embed.admin.services') }}</legend>
                @foreach ($serviceOptions as $key => $label)
                    <label>
                        <input type="checkbox" name="enabled_services[]" value="{{ $key }}"
                            @checked(in_array($key, old('enabled_services', $form->enabled_services ?? array_keys($serviceOptions)), true))>
                        {{ $label }}
                    </label>
                @endforeach
            </fieldset>

            <p>
                <label for="allowed_origins_text">{{ __('tickets::embed.admin.origins') }}</label>
                <textarea id="allowed_origins_text" name="allowed_origins_text" rows="6" required>{{ $originsText }}</textarea>
                <span class="muted">{{ __('tickets::embed.admin.origins_help') }}</span>
            </p>

            <p>
                <label for="spam_score_threshold">{{ __('tickets::embed.admin.threshold') }}</label>
                <input id="spam_score_threshold" name="spam_score_threshold" type="number" min="0" max="1" step="0.01"
                    value="{{ old('spam_score_threshold', $form->spam_score_threshold ?? '') }}">
            </p>

            <p>
                <label for="confirmation_text">{{ __('tickets::embed.admin.confirmation') }}</label>
                <textarea id="confirmation_text" name="confirmation_text" rows="3">{{ old('confirmation_text', $form->confirmation_text ?? '') }}</textarea>
            </p>

            <p>
                <button type="submit">{{ __('tickets::embed.admin.save') }}</button>
            </p>
        </form>

        @if ($form)
            <section>
                <h2>{{ __('tickets::embed.admin.snippet') }}</h2>
                <p>{{ __('tickets::embed.admin.public_key') }}: <code>{{ $form->public_key }}</code></p>
                <p>{{ __('tickets::embed.admin.loader_url') }}: <code>{{ $loaderUrl }}</code></p>
                <pre><code>{{ $embedSnippet }}</code></pre>

                <form method="post" action="{{ route('admin.tickets.embed-forms.toggle', $form) }}">
                    @csrf
                    <button type="submit">{{ $form->is_active ? __('tickets::embed.admin.toggle_off') : __('tickets::embed.admin.toggle_on') }}</button>
                </form>
                <form method="post" action="{{ route('admin.tickets.embed-forms.rotate', $form) }}" data-confirm="{{ __('tickets::embed.admin.rotate_confirm') }}">
                    @csrf
                    <button type="submit">{{ __('tickets::embed.admin.rotate') }}</button>
                </form>
                <form method="post" action="{{ route('admin.tickets.embed-forms.destroy', $form) }}" data-confirm="{{ __('tickets::embed.admin.delete_confirm') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit">{{ __('tickets::embed.admin.delete') }}</button>
                </form>
            </section>
        @endif
    </div>
@endsection
