{{--
    Admin-edited email body (#486) on the shared brand shell. The body is
    sanitised rich text with merge fields already filled in.
--}}
@component('mail.brand.message')
@if (! empty($greeting))
{{ $greeting }}

@endif
@component('mail::rich-body')
{!! $customBody !!}
@endcomponent

@php($buttonUrl = $actionUrl ?? ($url ?? null))
@if ($buttonUrl)
@component('mail::button', ['url' => $buttonUrl])
{{ $actionText ?? __('mail.brand.custom_body_action') }}
@endcomponent
@endif
@endcomponent
