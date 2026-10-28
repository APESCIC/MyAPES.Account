@component('mail.brand.message')
{{ __('mail.auth.reset.greeting', ['name' => $name]) }}

{{ __('mail.auth.reset.intro') }}

@component('mail::button', ['url' => $url])
{{ __('mail.auth.reset.action') }}
@endcomponent

{{ __('mail.auth.reset.expiry', ['count' => $expireMinutes]) }}

{{ __('mail.auth.reset.outro') }}

@slot('subcopy')
{{ __('mail.auth.reset.subcopy', ['url' => $url]) }}
@endslot
@endcomponent
