@extends('layouts.app')

@section('title', 'Complete account setup | MyAPES Account')

@section('content')
    <div class="panel">
        <h1>{{ __('public.profile.onboarding.blade.complete_your_account_setup') }}</h1>
        <p class="muted">{{ __('public.profile.onboarding.blade.confirm_your_uk_contact_details_services_and_optional_c') }}</p>
        <x-mascot-tip />
        <form method="post" action="{{ route('onboarding.update') }}">
            @csrf
            @method('put')
            @include('profile._account-fields')
            <button type="submit">{{ __('public.profile.onboarding.blade.complete_setup') }}</button>
        </form>
    </div>
@endsection
