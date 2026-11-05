@php($tourConfig = app(\App\Support\Tour\GuidedTour::class)->configFor(auth()->user(), request()))
<script type="application/json" data-guided-tour>{!! json_encode($tourConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
