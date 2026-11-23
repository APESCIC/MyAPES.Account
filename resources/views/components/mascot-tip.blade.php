@props([
    'variant' => 'aside',
    'title' => null,
    'body' => null,
    'critter' => null,
    'pose' => null,
])

@php
    $tips = app(\App\Support\MascotTips::class);
    $routeName = request()->route()?->getName();
    $imageClass = $variant === 'hero' ? 'hero-image' : 'brand-mascot';

    if (is_string($title) && is_string($body)) {
        $tip = ['title' => $title, 'body' => $body];
        $routeCast = $tips->castFor($routeName, $variant);
        $cast = $critter !== null
            ? ['name' => $critter, 'pose' => $pose ?? $routeCast['pose'] ?? 'thinking']
            : $routeCast;
    } else {
        $resolved = $tips->forRoute($routeName, $variant);
        $tip = $resolved ? ['title' => $resolved['title'], 'body' => $resolved['body']] : null;
        $cast = $resolved && $resolved['critter'] !== null
            ? ['name' => $resolved['critter'], 'pose' => $resolved['pose']]
            : null;
    }

    $critterName = $cast['name'] ?? (is_string($critter) ? $critter : null);
    $critterPose = $cast['pose'] ?? $pose;
    $fallbackImage = $variant === 'hero' ? 'mascot/spike-welcome.png' : 'mascot/spike-tip.png';
@endphp

@if($tip)
    <aside {{ $attributes->class(['mascot-tip', 'mascot-tip--'.$variant]) }}>
        @if ($critterName)
            <x-critter :name="$critterName" :pose="$critterPose ?? 'welcome'" class="{{ $imageClass }}" />
        @else
            <img
                src="{{ asset($fallbackImage) }}"
                alt=""
                class="{{ $imageClass }}"
                width="512"
                height="512"
                @if ($variant === 'empty') loading="lazy" decoding="async" @endif
            >
        @endif
        <div>
            <p class="mascot-tip__kicker">{{ __('public.chrome.mascot-tip.blade.spike_says') }}</p>
            <p><strong>{{ $tip['title'] }}</strong></p>
            <p>{{ $tip['body'] }}</p>
        </div>
    </aside>
@endif