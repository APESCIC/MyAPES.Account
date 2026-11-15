@component('mail.brand.message')
{{ __('mail.auth.set_password.greeting', ['name' => $name]) }}

{{ __('mail.auth.set_password.intro') }}

@component('mail::button', ['url' => $url])
{{ __('mail.auth.set_password.action') }}
@endcomponent

{{ __('mail.auth.set_password.expiry', ['count' => $expireMinutes]) }}

{{ __('mail.auth.set_password.outro') }}

@slot('subcopy')
{{ __('mail.auth.set_password.subcopy', ['url' => $url]) }}
@endslot
@endcomponent
