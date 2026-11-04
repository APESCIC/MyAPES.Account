@extends('layouts.app')

@section('title', __('help.staff.title').' | '.__('workspace.app_name'))

@section('content')
    <article class="panel legal-page" data-help-page="staff">
        <p class="eyebrow">{{ __('help.staff.eyebrow') }}</p>
        <h1>{{ __('help.staff.title') }}</h1>
        <p class="muted">{{ __('help.staff.intro') }}</p>
        <x-mascot-tip />
        <nav aria-label="{{ __('public.legal._nav.blade.legal_and_help_pages') }}">
            <ul class="legal-nav">
                <li><a href="{{ route('help') }}">{{ __('help.staff.public_guide_link') }}</a></li>
                <li><a href="{{ route('help.staff') }}" aria-current="page">{{ __('help.nav.staff_guide') }}</a></li>
                @if($showAdminGuideLink ?? false)
                    <li><a href="{{ route('help.admin') }}">{{ __('help.nav.admin_guide') }}</a></li>
                @endif
            </ul>
        </nav>

        <section id="areas">
            <h2>{{ __('help.staff.areas.heading') }}</h2>
            <ul>
                <li>{{ __('help.staff.areas.public') }}</li>
                <li>{{ __('help.staff.areas.staff') }}</li>
                <li>{{ __('help.staff.areas.admin') }}</li>
            </ul>
        </section>

        <section id="dashboard">
            <h2>{{ __('help.staff.dashboard.heading') }}</h2>
            <p>{{ __('help.staff.dashboard.body') }}</p>
        </section>

        <section id="services">
            <h2>{{ __('help.staff.services.heading') }}</h2>
            <p>{{ __('help.staff.services.body') }}</p>
            <p>{{ __('help.staff.services.missing') }}</p>
        </section>

        <section id="pets">
            <h2>{{ __('help.staff.pets.heading') }}</h2>
            <p>{{ __('help.staff.pets.body') }}</p>
            <p>{{ __('help.staff.pets.access') }}</p>
        </section>

        <section id="callers">
            <h2>{{ __('help.staff.callers.heading') }}</h2>
            <p>{{ __('help.staff.callers.body') }}</p>
            <p>{{ __('help.staff.callers.lock') }}</p>
            <p>{{ __('help.staff.callers.end') }}</p>
        </section>

        <section id="call-logs">
            <h2>{{ __('help.staff.call_logs.heading') }}</h2>
            <p>{{ __('help.staff.call_logs.body') }}</p>
        </section>

        <section id="editor">
            <h2>{{ __('help.staff.editor.heading') }}</h2>
            <p>{{ __('help.staff.editor.body') }}</p>
        </section>

        <section id="search">
            <h2>{{ __('help.staff.search.heading') }}</h2>
            <p>{{ __('help.staff.search.body') }}</p>
        </section>

        <section id="account">
            <h2>{{ __('help.staff.account.heading') }}</h2>
            <p>{{ __('help.staff.account.body') }}</p>
        </section>

        <section id="support">
            <h2>{{ __('help.staff.support.heading') }}</h2>
            <p>{{ __('help.staff.support.body') }}</p>
        </section>
    </article>
@endsection
