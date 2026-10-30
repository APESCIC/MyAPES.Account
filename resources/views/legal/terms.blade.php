@extends('layouts.app')

@section('title', __('seo.terms.title'))
@section('meta_description', __('seo.terms.description'))
@section('meta_keywords', __('seo.terms.keywords'))

@section('content')
    <article class="panel legal-page" data-legal-page="terms">
        <p class="eyebrow">{{ __('public.legal.cookies.blade.public_notice') }}</p>
        <h1>{{ __('public.legal.terms.blade.terms_of_use') }}</h1>
        <p class="muted">{{ __('public.legal.terms.blade.the_rules_for_using_myapes_core_as_a_public_service_use') }}</p>
        @include('legal._nav')

        <section>
            <h2>{{ __('public.legal.terms.blade.the_service') }}</h2>
            <p>MyAPES Account is the service portal run by Association of Protecting Exotic Species CIC (CIC No. 16253848) for APES CIC, APES Shelter and Rescue, and APES Pet Care Clinic. These terms cover use of the portal. They do not replace veterinary advice, rescue decisions, or a separate agreement APES CIC may have with you.</p>
        </section>

        <section>
            <h2>{{ __('public.legal.terms.blade.accounts') }}</h2>
            <p>Public accounts are for service users. Keep your password to yourself, use accurate details, and tell us if you think someone else has used your account. Staff and administrators must use Staff Login through APES Cloudron and follow the access they are given.</p>
            <p>{{ __('public.legal.terms.blade.we_may_suspend_or_close_an_account_if_these_terms_are_b') }}</p>
        </section>

        <section>
            <h2>{{ __('public.legal.terms.blade.using_myapes_core') }}</h2>
            <ul>
                <li>{{ __('public.legal.terms.blade.use_the_portal_only_for_genuine_apes_cic_shelter_or_cli') }}</li>
                <li>{{ __('public.legal.terms.blade.do_not_upload_material_you_do_not_have_the_right_to_sha') }}</li>
                <li>{{ __('public.legal.terms.blade.do_not_try_to_open_another_person_s_records_or_to_bypas') }}</li>
                <li>{{ __('public.legal.terms.blade.treat_messages_tickets_and_case_notes_as_confidential_s') }}</li>
            </ul>
        </section>

        <section>
            <h2>{{ __('public.legal.terms.blade.content_and_availability') }}</h2>
            <p>Records you add stay available to the people who need them for support and animal-care work. We aim to keep MyAPES Account available, but we may take it offline for maintenance, security, or operational reasons. The <a href="{{ route('change-log.index') }}">{{ __('public.change_log.change_log_hub') }}</a> lists released changes.</p>
        </section>

        <section>
            <h2>{{ __('public.legal.terms.blade.privacy_and_cookies') }}</h2>
            <p>The <a href="{{ route('privacy') }}">{{ __('public.legal.cookies.blade.privacy_notice') }}</a> explains how we use personal information. The <a href="{{ route('cookies') }}">{{ __('public.legal.help.blade.cookie_notice') }}</a> explains the essential cookies the portal sets. Creating a public account means you accept these terms and the way those notices describe the service.</p>
        </section>

        <section>
            <h2>{{ __('public.legal.terms.blade.changes') }}</h2>
            <p>We may update these terms when the portal or the law changes. The date at the top of this page shows the current version. Continued use after an update means you accept the revised terms.</p>
            <p>Questions about these terms can start from the <a href="{{ route('help') }}">{{ __('public.chrome.app.blade.help') }}</a> page or, once you are signed in, an APES CIC ticket or case.</p>
        </section>
    </article>
@endsection
