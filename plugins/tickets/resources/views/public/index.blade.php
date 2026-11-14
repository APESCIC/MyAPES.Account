@extends('layouts.app')

@section('title', __('tickets::public.title').' | '.__('workspace.app_name'))

@section('content')
    <div class="panel" data-public-open-ticket>
        <h1>{{ __('tickets::public.title') }}</h1>
        <p class="muted">@registrationOpen{{ __('tickets::public.intro') }}@else{{ __('tickets::public.intro_closed') }}@endregistrationOpen</p>
        <x-mascot-tip />
    </div>

    @forelse($services as $service)
        <section class="panel" aria-labelledby="open-ticket-{{ $service['key'] }}" data-public-open-ticket-service="{{ $service['key'] }}">
            <h2 id="open-ticket-{{ $service['key'] }}">{{ $service['name'] }}</h2>
            <p class="muted">{{ $service['description'] }}</p>
            <h3>{{ __('tickets::public.areas_heading') }}</h3>
            <ul class="stack-spaced">
                @foreach($service['areas'] as $area)
                    <li>
                        <strong>{{ $area['label'] }}</strong>
                        <span class="actions">
                            @auth
                                <a href="{{ route('tickets.public.start', ['service' => $service['key'], 'area' => $area['key'], 'via' => 'login']) }}">{{ __('tickets::public.open_for_area', ['area' => $area['label']]) }}</a>
                            @else
                                <a href="{{ route('tickets.public.start', ['service' => $service['key'], 'area' => $area['key'], 'via' => 'login']) }}">{{ __('tickets::public.sign_in_to_open') }}</a>
                                @registrationOpen
                                    <a href="{{ route('tickets.public.start', ['service' => $service['key'], 'area' => $area['key'], 'via' => 'register']) }}">{{ __('tickets::public.register_to_open') }}</a>
                                @endregistrationOpen
                            @endauth
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <div class="panel">
            <p>{{ __('tickets::public.none') }}</p>
        </div>
    @endforelse
@endsection
