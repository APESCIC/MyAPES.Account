@extends('layouts.app')

@section('title', __('admin.emails.page_title'))

@section('content')
    @include('admin._navigation')

    <header class="page-heading">
        <div>
            <p class="eyebrow">{{ __('admin.emails.eyebrow') }}</p>
            <h1>{{ __('admin.emails.heading') }}</h1>
            <p>{{ __('admin.emails.intro') }}</p>
        </div>
    </header>

    <div class="panel-stack">
        <section class="panel" aria-labelledby="email-templates-heading">
            <h2 id="email-templates-heading">{{ __('admin.emails.templates') }}</h2>
            <p>{{ __('admin.emails.categories_help') }}</p>
            <div class="table-wrap" role="region" aria-label="{{ __('admin.emails.templates') }}" tabindex="0">
                <table>
                    <caption>{{ __('admin.emails.templates_caption', ['count' => count($templates)]) }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.emails.name') }}</th>
                            <th scope="col">{{ __('admin.emails.category') }}</th>
                            <th scope="col">{{ __('admin.emails.owner') }}</th>
                            <th scope="col">{{ __('admin.emails.key') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($templates as $template)
                            <tr data-mail-template="{{ $template->key }}">
                                <td>
                                    <a href="{{ route('admin.emails.show', $template->key) }}">{{ $template->label() }}</a>
                                    <p class="muted">{{ $template->description() }}</p>
                                </td>
                                <td>{{ $template->category->label() }}</td>
                                <td>{{ $template->ownerLabel() }}</td>
                                <td><code>{{ $template->key }}</code></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
