@props(['onlyStaffSide' => false])

@if(! $onlyStaffSide || (auth()->check() && app(\App\Core\Accounts\AccountClassifier::class)->isStaffSide(auth()->user())))
    <x-alert tone="info" :title="__('public.account_separation.title')" {{ $attributes->merge(['data-public-account-required' => true]) }}>
        <p>{{ __('public.account_separation.body') }}</p>
        <p>{{ __('public.account_separation.steps') }}</p>
        <div class="actions">
            @registrationOpen
                <a href="{{ route('public.register') }}">{{ __('public.account_separation.register') }}</a>
            @endregistrationOpen
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
