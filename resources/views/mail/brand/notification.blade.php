{{--
    Line-based MailMessage (greeting, lines, action) on the shared brand shell (#355).
    Used by MailMessage::markdown('mail.brand.notification') instead of Laravel's stock theme.
--}}
@component('mail.brand.message', ['replyByEmail' => $replyByEmail ?? false])
@if (! empty($replyByEmail))
{{ __('mail.reply_by_email.marker') }}

@endif
@if (! empty($greeting))
{{ $greeting }}
@endif

@foreach ($introLines as $line)
{{ $line }}

@endforeach
@isset($actionText)
@component('mail::button', ['url' => $actionUrl])
{{ $actionText }}
@endcomponent
@endisset

@foreach ($outroLines as $line)
{{ $line }}

@endforeach
@isset($actionText)
@slot('subcopy')
{{ __('mail.brand.action_fallback', ['url' => $displayableActionUrl]) }}
@endslot
@endisset
@endcomponent
