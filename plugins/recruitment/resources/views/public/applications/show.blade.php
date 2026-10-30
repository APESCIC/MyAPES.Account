@extends('layouts.app')

@section('title', 'Application | MyAPES Account')

@section('content')
    @include('recruitment::_navigation')

    @inject('ukDateTime', \App\Support\UkDateTime::class)
    <div class="panel" data-recruitment-application-detail>
        <span class="service-label service-apes-cic">{{ __('recruitment::public.index.blade.apes_cic') }}</span>
        <h1>{{ $application->recruitmentRole?->title ?? 'Application' }}</h1>
        <p class="muted">Status: {{ $statusLabels[$application->status] ?? $application->status }}</p>
        <dl class="ticket-meta">
            <div>
                <dt>{{ __('recruitment::public.index.blade.submitted') }}</dt>
                <dd>{{ $ukDateTime->formatDate($application->submitted_at) ?? '—' }}</dd>
            </div>
            <div>
                <dt>{{ __('recruitment::public.show.blade.application_id') }}</dt>
                <dd>#{{ $application->id }}</dd>
            </div>
            @if($application->withdrawn_at)
                <div>
                    <dt>{{ __('recruitment::public.show.blade.withdrawn') }}</dt>
                    <dd>{{ $ukDateTime->formatDate($application->withdrawn_at) }}</dd>
                </div>
            @endif
        </dl>
        @if($application->statement)
            <h2>{{ __('recruitment::public.show.blade.your_statement') }}</h2>
            <div class="stack-spaced">
                {!! nl2br(e($application->statement)) !!}
            </div>
        @endif
        <div class="actions">
            <a href="{{ route('recruitment.applications.index') }}">{{ __('recruitment::public.show.blade.back_to_my_applications') }}</a>
            @if($application->recruitmentRole?->isOpen())
                <a href="{{ route('recruitment.show', $application->recruitmentRole) }}">{{ __('recruitment::public.show.blade.view_role') }}</a>
            @endif
        </div>
        @if($canWithdraw)
            <form method="post" action="{{ route('recruitment.applications.withdraw', $application) }}">
                @csrf
                <button type="submit">{{ __('recruitment::public.show.blade.withdraw_application') }}</button>
            </form>
        @endif
    </div>
@endsection
