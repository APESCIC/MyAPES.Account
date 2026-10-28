@component('mail.brand.message')
{{ __('mail.auth.verify.greeting', ['name' => $name]) }}

{{ __('mail.auth.verify.intro') }}

@component('mail::button', ['url' => $url])
{{ __('mail.auth.verify.action') }}
@endcomponent

{{ __('mail.auth.verify.outro') }}

@slot('subcopy')
{{ __('mail.auth.verify.subcopy', ['url' => $url]) }}
@endslot
@endcomponent
