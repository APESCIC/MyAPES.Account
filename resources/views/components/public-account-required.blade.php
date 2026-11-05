@props(['onlyStaffSide' => false, 'withTitle' => true])

@php
    $viewer = auth()->user();
    $showGuidance = ! $onlyStaffSide
        || ($viewer !== null && app(\App\Core\Accounts\AccountClassifier::class)->isStaffSide($viewer));
@endphp

@if($showGuidance)
    <x-alert tone="info" :title="$withTitle ? __('public.account_separation.title') : null" {{ $attributes->merge(['data-public-account-required' => true]) }}>
        <p>{{ __('public.account_separation.body') }}</p>
        <p>{{ __('public.account_separation.steps') }}</p>
        <div class="actions">
            <a href="{{ route('public.register') }}">{{ __('public.account_separation.register') }}</a>
            <a href="{{ route('public.login') }}">{{ __('public.account_separation.sign_in') }}</a>
            <a href="{{ route('help') }}#staff-and-personal-accounts">{{ __('public.account_separation.learn_more') }}</a>
            @auth
                <form method="post" action="{{ route('auth.logout') }}">
                    @csrf
                    <button type="submit" class="button-secondary">{{ __('public.account_separation.log_out') }}</button>
                </form>
            @endauth
        </div>
    </x-alert>
@endif
