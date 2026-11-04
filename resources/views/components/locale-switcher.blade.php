@php
    $supportedLocales = config('app.supported_locales', []);
@endphp
@if(is_array($supportedLocales) && count($supportedLocales) > 1)
    <form method="post" action="{{ route('locale.store') }}" class="locale-switcher">
        @csrf
        <label for="locale-switcher">{{ __('public.locale.switcher_label') }}</label>
        <select id="locale-switcher" name="locale" data-auto-submit>
            @foreach($supportedLocales as $code => $label)
                <option value="{{ $code }}" @selected(app()->getLocale() === $code)>{{ $label }}</option>
            @endforeach
        </select>
    </form>
@endif
