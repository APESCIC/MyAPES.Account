@extends('layouts.app')

@section('title', __('qa.index.title'))

@section('content')
    <div class="panel">
        <h1>{{ __('qa.index.heading') }}</h1>
        <p class="muted">{{ __('qa.index.intro') }}</p>
        <x-qa.signin-panel :profiles="$qaProfiles" />
        @if ($currentProfile !== null)
            <form method="post" action="{{ route('qa.logout') }}" class="mt-2">
                @csrf
                <button type="submit" class="sg-btn sg-btn-secondary">
                    <i data-lucide="log-out" aria-hidden="true"></i>
                    {{ __('qa.sign_out') }}
                </button>
            </form>
        @endif
    </div>
@endsection
