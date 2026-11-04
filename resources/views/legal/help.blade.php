@extends('layouts.app')

@section('title', __('seo.help.title'))
@section('meta_description', __('seo.help.description'))
@section('meta_keywords', __('seo.help.keywords'))

@section('content')
    <article class="panel legal-page" data-legal-page="help">
        <p class="eyebrow">{{ __('public.legal.help.blade.public_guide') }}</p>
        <h1>{{ __('public.chrome.app.blade.help') }}</h1>
        <p class="muted">{{ __('public.legal.help.blade.short_answers_for_public_accounts_staff_sign_in_and_how') }}</p>
        <x-mascot-tip />
        @include('legal._nav')
        @if($showStaffGuideLink ?? false)
            <p data-help-staff-link>{{ __('help.public_guide.staff_link_intro') }} <a href="{{ route('help.staff') }}">{{ __('help.public_guide.staff_link') }}</a>.</p>
        @endif
        @auth
            <p data-tour-restart>{{ __('tour.restart_intro') }} <a href="{{ route('dashboard', [\App\Support\Tour\GuidedTour::RESTART_QUERY => 1]) }}">{{ __('tour.restart') }}</a>.</p>
        @endauth

        <section>
            <h2>{{ __('public.legal.help.blade.which_sign_in_should_i_use') }}</h2>
            <p><a href="{{ route('public.login') }}">{{ __('public.chrome.app.blade.public_login') }}</a> is for service users who created a MyAPES account. Use your username or email and the password you chose at register.</p>
            <p><a href="{{ route('staff.login') }}">{{ __('public.chrome.app.blade.staff_login') }}</a> is for APES staff and administrators. Those accounts sign in through APES Cloudron, not the public password form.</p>
        </section>

        <section>
            <h2>{{ __('public.legal.help.blade.create_or_recover_a_public_account') }}</h2>
            <p>Use <a href="{{ route('public.register') }}">{{ __('public.chrome.app.blade.register') }}</a> to create a public account, then open the verification link we send to your email before you finish profile setup.</p>
            <p>{{ __('public.legal.help.blade.if_you_cannot_sign_in_public_login_has_a_forgot_passwor') }}</p>
        </section>

        <section>
            <h2>{{ __('public.legal.help.blade.once_you_are_signed_in') }}</h2>
            <ul>
                <li>{{ __('public.legal.help.blade.dashboard_shows_work_that_needs_you_then_the_services_y') }}</li>
                <li>{{ __('public.legal.help.blade.profile_holds_your_contact_details_and_which_myapes_ser') }}</li>
                <li>{{ __('public.legal.help.blade.apes_cic_is_for_organisational_support_tickets_and_form') }}</li>
                <li>{{ __('public.legal.help.blade.apes_shelter_and_rescue_holds_pet_profiles_and_rescue_c') }}</li>
                <li>{{ __('public.legal.help.blade.apes_pet_care_clinic_holds_clinic_pet_records_tickets_a') }}</li>
            </ul>
        </section>

        <section>
            <h2>{{ __('public.legal.help.blade.ask_apes_cic_for_help') }}</h2>
            <p>Signed-in public users should open a ticket in the service they need. Use an APES CIC case when the matter is a privacy request, complaint, or other formal casework.</p>
            <p>{{ __('public.legal.help.blade.if_something_in_the_software_looks_broken_the_app_suppo') }}</p>
        </section>

        <section>
            <h2>{{ __('public.legal.help.blade.privacy_and_data_requests') }}</h2>
            <p>{{ __('public.legal.help.blade.if_you_already_have_a_public_account_sign_in_and_open_a') }}</p>
            <p>If you cannot sign in, <a href="{{ route('public.register') }}">{{ __('public.legal.help.blade.create_a_public_account') }}</a> first, or contact APES CIC through the <a href="https://www.apes.org.uk" rel="noopener noreferrer">{{ __('public.legal.help.blade.apes_website') }}</a>.</p>
        </section>

        <section>
            <h2>{{ __('public.legal.help.blade.notices') }}</h2>
            <p>Read the <a href="{{ route('privacy') }}">{{ __('public.legal.cookies.blade.privacy_notice') }}</a>, <a href="{{ route('cookies') }}">{{ __('public.legal.help.blade.cookie_notice') }}</a>, and <a href="{{ route('terms') }}">{{ __('public.legal.cookies.blade.terms_of_use') }}</a> for how the portal handles information and account use.</p>
        </section>
    </article>
@endsection
