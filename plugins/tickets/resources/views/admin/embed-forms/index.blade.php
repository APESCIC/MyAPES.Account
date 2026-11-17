@extends('layouts.app')

@section('title', __('tickets::embed.admin.index_heading'))

@section('content')
    <div class="sg-page">
        <header class="sg-page__header">
            <h1>{{ __('tickets::embed.admin.index_heading') }}</h1>
            <p>{{ __('tickets::embed.admin.index_intro') }}</p>
            <p>
                <a class="button" href="{{ route('admin.tickets.embed-forms.create') }}">{{ __('tickets::embed.admin.create') }}</a>
            </p>
        </header>

        @if (session('status'))
            <p class="alert alert-success" role="status">{{ session('status') }}</p>
        @endif

        @if ($forms->isEmpty())
            <p>{{ __('tickets::embed.admin.none') }}</p>
        @else
            <table class="sg-table">
                <thead>
                    <tr>
                        <th>{{ __('tickets::embed.admin.name') }}</th>
                        <th>{{ __('tickets::embed.admin.public_key') }}</th>
                        <th>{{ __('tickets::embed.admin.active') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($forms as $form)
                        <tr>
                            <td>{{ $form->name }}</td>
                            <td><code>{{ $form->public_key }}</code></td>
                            <td>{{ $form->is_active ? __('tickets::embed.admin.status_active') : __('tickets::embed.admin.status_inactive') }}</td>
                            <td><a href="{{ route('admin.tickets.embed-forms.edit', $form) }}">{{ __('tickets::embed.admin.edit') }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
