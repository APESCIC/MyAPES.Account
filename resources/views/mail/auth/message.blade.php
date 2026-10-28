{{--
    Auth email brand kit (#226) on the shared brand layout (#352).
    Used by auth security notifications via MailMessage::markdown().
--}}
@component('mail.brand.layout')
{{-- Header --}}
@slot('header')
<x-mail::header :url="config('app.url')">
<img
    src="{{ url('/logos/myapes-header-light-600x128.png') }}"
    alt="{{ __('terms.app_name') }}"
    class="logo"
    style="height: 48px; max-height: 48px; width: auto;"
>
</x-mail::header>
@endslot

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
@slot('subcopy')
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
@endslot
@endisset

{{-- Footer --}}
@slot('footer')
<x-mail::footer>
{!! __('mail.auth.footer.copyright', [
    'year' => date('Y'),
    'app' => __('terms.app_name'),
]) !!}
<br>
<a href="{{ route('help') }}" style="color: #6b7280; text-decoration: underline;">
    {{ __('mail.auth.footer.help') }}
</a>
<br>
{{ __('mail.auth.footer.salutation') }}
</x-mail::footer>
@endslot
@endcomponent
