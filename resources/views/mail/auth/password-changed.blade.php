@component('mail.brand.message')
{{ __('mail.auth.password_changed.greeting', ['name' => $name]) }}

{{ __('mail.auth.password_changed.intro') }}

@component('mail::button', ['url' => $url])
{{ __('mail.auth.password_changed.action') }}
@endcomponent

{{ __('mail.auth.password_changed.outro') }}
@endcomponent
