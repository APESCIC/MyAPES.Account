@component('mail.brand.message')
{{ __('mail.auth.welcome_sign_in.greeting', ['name' => $name]) }}

{{ __('mail.auth.welcome_sign_in.intro') }}

{{ __('mail.auth.welcome_sign_in.username_label') }}: **{{ $username }}**

{{ __('mail.auth.welcome_sign_in.password_label') }}: **{{ $temporaryPassword }}**

{{ __('mail.auth.welcome_sign_in.expiry', ['hours' => $ttlHours]) }}

{{ __('mail.auth.welcome_sign_in.forced_change') }}

@component('mail::button', ['url' => $loginUrl])
{{ __('mail.auth.welcome_sign_in.action') }}
@endcomponent

{{ __('mail.auth.welcome_sign_in.support', ['contact' => $supportContact]) }}

{{ __('mail.auth.welcome_sign_in.privacy', ['url' => $privacyUrl]) }}

@slot('subcopy')
{{ __('mail.auth.welcome_sign_in.subcopy', ['url' => $loginUrl]) }}
@endslot
@endcomponent
