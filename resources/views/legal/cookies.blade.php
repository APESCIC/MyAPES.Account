@extends('layouts.app')

@section('title', __('seo.cookies.title'))
@section('meta_description', __('seo.cookies.description'))
@section('meta_keywords', __('seo.cookies.keywords'))

@section('content')
    <article class="panel legal-page" data-legal-page="cookies">
        <p class="eyebrow">{{ __('public.legal.cookies.blade.public_notice') }}</p>
        <h1>{{ __('public.legal.cookies.blade.cookie_notice') }}</h1>
        <p class="muted">{{ __('public.legal.cookies.blade.the_cookies_and_similar_storage_myapes_core_uses_to_kee') }}</p>
        @include('legal._nav')

        <section>
            <h2>{{ __('public.legal.cookies.blade.what_we_use') }}</h2>
            <p>MyAPES Account sets a small number of essential cookies so the site can sign you in, protect forms, and remember a public session if you ask it to. We do not set advertising cookies, and we do not use third-party analytics cookies on these pages.</p>
        </section>

        <section>
            <h2>{{ __('public.legal.cookies.blade.essential_cookies') }}</h2>
            <ul>
                <li><strong>Session.</strong> Keeps you signed in while you move between pages and expires when the session ends.</li>
                <li><strong>{{ __('public.legal.cookies.blade.security_token') }}</strong> Helps stop another site from submitting a form in your name.</li>
                <li><strong>{{ __('public.legal.cookies.blade.remember_me') }}</strong> Set only if you tick Remember me on Public Login, so a public account can stay signed in on that browser for longer.</li>
            </ul>
            <p>{{ __('public.legal.cookies.blade.staff_sign_in_through_apes_cloudron_may_also_use_cookie') }}</p>
        </section>

        <section>
            <h2>{{ __('public.legal.cookies.blade.storage_that_is_not_a_cookie') }}</h2>
            <p>The theme control (light or dark) is stored in your browser’s local storage so the next visit can keep your choice. That setting stays on your device and is not sent as a cookie.</p>
        </section>

        <section>
            <h2>{{ __('public.legal.cookies.blade.managing_cookies') }}</h2>
            <p>{{ __('public.legal.cookies.blade.you_can_delete_or_block_cookies_in_your_browser_if_you_') }}</p>
            <p>The <a href="{{ route('privacy') }}">{{ __('public.legal.cookies.blade.privacy_notice') }}</a> explains how APES CIC uses personal information. The <a href="{{ route('terms') }}">{{ __('public.legal.cookies.blade.terms_of_use') }}</a> cover using the service.</p>
        </section>
    </article>
@endsection
