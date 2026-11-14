{{-- Soft info banner from Admin → Settings → General (#739); plain text, not shown on Admin pages. --}}
@if(($siteBanner ?? null) !== null)
    <x-alert tone="info" data-site-banner>
        <p>{{ $siteBanner }}</p>
    </x-alert>
@endif
