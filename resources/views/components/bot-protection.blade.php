@props(['form'])
@php($guard = app(\App\Core\BotProtection\BotProtection::class))
@if($guard->appliesTo(request()))
    <div class="visually-hidden" aria-hidden="true" data-honeypot>
        <label for="{{ $form }}-{{ \App\Core\BotProtection\BotProtection::HONEYPOT_FIELD }}">{{ __('public.bot_protection.honeypot_label') }}</label>
        <input type="text" id="{{ $form }}-{{ \App\Core\BotProtection\BotProtection::HONEYPOT_FIELD }}" name="{{ \App\Core\BotProtection\BotProtection::HONEYPOT_FIELD }}" value="" tabindex="-1" autocomplete="off">
    </div>
    <input type="hidden" name="{{ \App\Core\BotProtection\BotProtection::STARTED_FIELD }}" value="{{ $guard->startedToken() }}">
    @if($guard->recaptchaActive($form, request()))
        @once
            @push('head')
                <script src="https://www.google.com/recaptcha/api.js?render={{ rawurlencode((string) $guard->siteKey()) }}" async defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
            @endpush
        @endonce
        <input type="hidden" name="{{ \App\Core\BotProtection\BotProtection::TOKEN_FIELD }}" value="" data-recaptcha-token data-recaptcha-action="{{ $form }}" data-recaptcha-site-key="{{ $guard->siteKey() }}" data-recaptcha-unavailable="{{ \App\Core\BotProtection\RecaptchaVerifier::UNAVAILABLE_TOKEN }}">
        <p class="muted" data-recaptcha-notice>
            {{ __('public.bot_protection.notice_before') }}
            <a href="https://policies.google.com/privacy" rel="noopener noreferrer" target="_blank">{{ __('public.bot_protection.google_privacy') }}</a>
            {{ __('public.bot_protection.notice_and') }}
            <a href="https://policies.google.com/terms" rel="noopener noreferrer" target="_blank">{{ __('public.bot_protection.google_terms') }}</a>
            {{ __('public.bot_protection.notice_after') }}
        </p>
    @endif
@endif
