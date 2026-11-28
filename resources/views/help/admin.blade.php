@extends('layouts.app')

@section('title', __('help.admin.title').' | '.__('workspace.app_name'))

@section('content')
    <article class="panel legal-page" data-help-page="admin">
        <p class="eyebrow">{{ __('help.admin.eyebrow') }}</p>
        <h1>{{ __('help.admin.title') }}</h1>
        <p class="muted">{{ __('help.admin.intro') }}</p>
        <x-mascot-tip />
        <nav aria-label="{{ __('public.legal._nav.blade.legal_and_help_pages') }}">
            <ul class="legal-nav">
                <li><a href="{{ route('help') }}">{{ __('help.staff.public_guide_link') }}</a></li>
                <li><a href="{{ route('help.staff') }}">{{ __('help.nav.staff_guide') }}</a></li>
                <li><a href="{{ route('help.admin') }}" aria-current="page">{{ __('help.nav.admin_guide') }}</a></li>
            </ul>
        </nav>

        <section id="overview">
            <h2>{{ __('help.admin.overview.heading') }}</h2>
            <p>{{ __('help.admin.overview.body') }}</p>
            <p>{{ __('help.admin.overview.alerts') }}</p>
        </section>

        <section id="people">
            <h2>{{ __('help.admin.people.heading') }}</h2>
            <p>{{ __('help.admin.people.body') }}</p>
        </section>

        <section id="security">
            <h2>{{ __('help.admin.security.heading') }}</h2>
            <p>{{ __('help.admin.security.body') }}</p>
            <p>{{ __('help.admin.security.access') }}</p>
            <p>{{ __('help.admin.security.keys') }}</p>
            <p>{{ __('help.admin.security.headers') }}</p>
        </section>

        <section id="modules">
            <h2>{{ __('help.admin.modules.heading') }}</h2>
            <p>{{ __('help.admin.modules.body') }}</p>
            <p>{{ __('help.admin.modules.versions') }}</p>
        </section>

        <section id="communication">
            <h2>{{ __('help.admin.communication.heading') }}</h2>
            <p>{{ __('help.admin.communication.body') }}</p>
            <p>{{ __('help.admin.communication.import') }}</p>
            <p>{{ __('help.admin.communication.replies') }}</p>
        </section>

        <section id="system">
            <h2>{{ __('help.admin.system.heading') }}</h2>
            <p>{{ __('help.admin.system.body') }}</p>
            <p>{{ __('help.admin.system.maintenance') }}</p>
        </section>

        <section id="lists">
            <h2>{{ __('help.admin.lists.heading') }}</h2>
            <p>{{ __('help.admin.lists.body') }}</p>
        </section>

        <section id="recruitment">
            <h2>{{ __('help.admin.recruitment.heading') }}</h2>
            <p>{{ __('help.admin.recruitment.body') }}</p>
        </section>
    </article>
@endsection
