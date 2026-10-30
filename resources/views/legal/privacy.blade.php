@extends('layouts.app')

@section('title', __('seo.privacy.title'))
@section('meta_description', __('seo.privacy.description'))
@section('meta_keywords', __('seo.privacy.keywords'))

@section('content')
    <article class="panel legal-page" data-legal-page="privacy">
        <p class="eyebrow">{{ __('public.legal.cookies.blade.public_notice') }}</p>
        <h1>{{ __('public.legal.privacy.blade.privacy_notice') }}</h1>
        <p class="muted">{{ __('public.legal.privacy.blade.how_association_of_protecting_exotic_species_cic_uses_p') }}</p>
        @include('legal._nav')

        <section>
            <h2>{{ __('public.legal.privacy.blade.who_we_are') }}</h2>
            <p>Association of Protecting Exotic Species CIC (CIC No. 16253848) is the controller for personal information processed in MyAPES Account. This notice covers the public portal used by service users, and the staff tools that support APES CIC, APES Shelter and Rescue, and APES Pet Care Clinic.</p>
            <p>You can also read more about APES CIC on the <a href="https://www.apes.org.uk" rel="noopener noreferrer">{{ __('public.legal.help.blade.apes_website') }}</a>.</p>
        </section>

        <section>
            <h2>{{ __('public.legal.privacy.blade.information_we_hold') }}</h2>
            <p>{{ __('public.legal.privacy.blade.when_you_create_a_public_account_we_store_the_name_emai') }}</p>
            <ul>
                <li>{{ __('public.legal.privacy.blade.uk_contact_details_and_optional_contact_preferences_fro') }}</li>
                <li>{{ __('public.legal.privacy.blade.the_myapes_services_you_choose_such_as_apes_cic_shelter') }}</li>
                <li>{{ __('public.legal.privacy.blade.pet_profiles_tickets_cases_and_comments_you_create_or_t') }}</li>
                <li>{{ __('public.legal.privacy.blade.technical_records_needed_to_keep_the_service_secure_suc') }}</li>
            </ul>
            <p>Staff accounts are created through APES Cloudron. Those accounts follow the same service records once a person is signed in, and directory groups decide what they can open.</p>
        </section>

        <section>
            <h2>{{ __('public.legal.privacy.blade.why_we_use_it') }}</h2>
            <p>We use this information to run MyAPES Account: to let you sign in, finish account setup, contact you about the services you asked for, and handle tickets, cases, and pet records. We rely on the steps you take in the app, our legitimate interest in running a safe support portal, and our public-interest work as a community interest company.</p>
            <p>{{ __('public.legal.privacy.blade.we_do_not_sell_personal_information_and_we_do_not_use_i') }}</p>
        </section>

        <section>
            <h2>{{ __('public.legal.privacy.blade.who_can_see_it') }}</h2>
            <p>Public users see their own profile, pets, and the tickets or cases available to them. Staff and administrators see the records their role allows, so they can respond to support and animal-care work. Hosting and email providers process information only as needed to run the service.</p>
        </section>

        <section>
            <h2>{{ __('public.legal.privacy.blade.how_long_we_keep_it') }}</h2>
            <p>We keep account and service records while you use MyAPES Account and for as long as we need them to deliver support, meet safeguarding or animal-welfare duties, or meet a legal requirement. Audit records are retained for a limited period and then removed.</p>
        </section>

        <section>
            <h2>{{ __('public.legal.privacy.blade.your_rights') }}</h2>
            <p>You can ask APES CIC for a copy of the personal information we hold, and you can ask us to correct it or, where the law allows, delete it or limit how we use it. You can also complain to the Information Commissioner's Office if you are unhappy with how we handle your information.</p>
            <p>Signed-in public users can start a privacy request through APES CIC cases. If you cannot sign in, create a <a href="{{ route('public.register') }}">{{ __('public.legal.privacy.blade.public_account') }}</a> and open a case, or contact APES CIC through the <a href="https://www.apes.org.uk" rel="noopener noreferrer">{{ __('public.legal.help.blade.apes_website') }}</a>.</p>
        </section>

        <section>
            <h2>{{ __('public.legal.privacy.blade.cookies_and_related_notices') }}</h2>
            <p>MyAPES Account uses a small set of essential cookies so you can sign in and stay signed in. The <a href="{{ route('cookies') }}">{{ __('public.legal.help.blade.cookie_notice') }}</a> explains those in more detail. Using the service is also covered by the <a href="{{ route('terms') }}">{{ __('public.legal.cookies.blade.terms_of_use') }}</a>.</p>
        </section>
    </article>
@endsection
