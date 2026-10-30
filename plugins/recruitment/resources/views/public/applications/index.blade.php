@extends('layouts.app')

@section('title', 'My applications | MyAPES Account')

@section('content')
    @include('recruitment::_navigation')

    @inject('ukDateTime', \App\Support\UkDateTime::class)
    <div class="panel">
        <span class="service-label service-apes-cic">{{ __('recruitment::public.index.blade.apes_cic') }}</span>
        <h1>{{ __('recruitment::public._navigation.blade.my_applications') }}</h1>
        <p class="muted">{{ __('recruitment::public.index.blade.openings_you_have_applied_for_with_their_current_status') }}</p>
        <div class="actions">
            <a href="{{ route('recruitment.index') }}">{{ __('recruitment::public.index.blade.browse_open_roles') }}</a>
        </div>
    </div>

    <div class="panel" id="list" data-recruitment-applications>
        <h2>{{ __('recruitment::public.index.blade.applications') }}</h2>
        @if($applications->isEmpty())
            <x-mascot-tip
                variant="empty"
                title="{{ __('recruitment::public.index.blade.no_applications_yet') }}"
                body="When you apply for an open role, it will appear here."
            />
        @else
            <table>
                <thead>
                    <tr>
                        <th>{{ __('recruitment::public.index.blade.role') }}</th>
                        <th>{{ __('recruitment::public.index.blade.status') }}</th>
                        <th>{{ __('recruitment::public.index.blade.submitted') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($applications as $application)
                        <tr>
                            <td>{{ $application->recruitmentRole?->title ?? 'Role removed' }}</td>
                            <td>{{ $statusLabels[$application->status] ?? $application->status }}</td>
                            <td>{{ $ukDateTime->formatDate($application->submitted_at) ?? '—' }}</td>
                            <td>
                                <a href="{{ route('recruitment.applications.show', $application) }}">{{ __('recruitment::public.index.blade.open') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            {{ $applications->links() }}
        @endif
    </div>
@endsection
