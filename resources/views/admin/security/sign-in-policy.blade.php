@extends('layouts.app')

@section('title', __('admin.security.sign_in_policy.page_title'))

@section('content')
    <header class="page-heading">
        <div>
            <p class="eyebrow">{{ __('admin.security.eyebrow') }}</p>
            <h1>{{ __('admin.security.sign_in_policy.heading') }}</h1>
            <p>{{ __('admin.security.sign_in_policy.intro') }}</p>
        </div>
    </header>

    <section class="panel" aria-labelledby="sign-in-policy-heading" data-security-tab="sign-in-policy">
        <h2 id="sign-in-policy-heading" class="sr-only">{{ __('admin.security.sign_in_policy.heading') }}</h2>

        @can('admin.settings.manage')
            <form method="post" action="{{ route('admin.security.sign-in-policy.update') }}" class="stacked-form">
                @csrf
                @method('PUT')

                <label for="temporary_password_ttl_hours">{{ __('admin.security.sign_in_policy.fields.temporary_password_ttl_hours') }}</label>
                <input id="temporary_password_ttl_hours" name="temporary_password_ttl_hours" type="number" min="1" max="720" value="{{ old('temporary_password_ttl_hours', $temporaryPasswordTtlHours) }}" required>
                <p class="muted">{{ __('admin.security.sign_in_policy.help.temporary_password_ttl_hours') }}</p>

                <label for="phone_pin_min_length">{{ __('admin.security.sign_in_policy.fields.phone_pin_min_length') }}</label>
                <input id="phone_pin_min_length" name="phone_pin_min_length" type="number" min="4" max="8" value="{{ old('phone_pin_min_length', $phonePinMinLength) }}" required>

                <label for="phone_pin_max_length">{{ __('admin.security.sign_in_policy.fields.phone_pin_max_length') }}</label>
                <input id="phone_pin_max_length" name="phone_pin_max_length" type="number" min="4" max="8" value="{{ old('phone_pin_max_length', $phonePinMaxLength) }}" required>
                <p class="muted">{{ __('admin.security.sign_in_policy.help.phone_pin_length') }}</p>

                <label class="checkbox">
                    <input type="checkbox" name="staff_two_factor_required" value="1" @checked(old('staff_two_factor_required', $staffTwoFactorRequired))>
                    {{ __('admin.security.sign_in_policy.fields.staff_two_factor_required') }}
                </label>
                <p class="muted">{{ __('admin.security.sign_in_policy.help.staff_two_factor_required') }}</p>

                <div class="actions">
                    <button type="submit">{{ __('admin.security.sign_in_policy.save') }}</button>
                </div>
            </form>
        @else
            <dl class="definition-list">
                <div>
                    <dt>{{ __('admin.security.sign_in_policy.fields.temporary_password_ttl_hours') }}</dt>
                    <dd>{{ $temporaryPasswordTtlHours }}</dd>
                </div>
                <div>
                    <dt>{{ __('admin.security.sign_in_policy.fields.phone_pin_length') }}</dt>
                    <dd>{{ $phonePinMinLength }}–{{ $phonePinMaxLength }}</dd>
                </div>
                <div>
                    <dt>{{ __('admin.security.sign_in_policy.fields.staff_two_factor_required') }}</dt>
                    <dd>{{ $staffTwoFactorRequired ? __('admin.security.sign_in_policy.yes') : __('admin.security.sign_in_policy.no') }}</dd>
                </div>
            </dl>
        @endcan
    </section>
@endsection
