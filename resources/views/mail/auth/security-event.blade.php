{{-- Shared stub body for future MFA / passkey / email-change security emails (#226). --}}
@component('mail.brand.message')
{{ $greeting }}

{{ $intro }}

@isset($url)
@component('mail::button', ['url' => $url])
{{ $action }}
@endcomponent
@endisset

{{ $outro }}
@endcomponent
