@props([
    'name',
    'pose' => 'welcome',
    'decorative' => false,
])

@php
    $art = \App\Support\Theme\CritterCast::resolve($name, $pose);
    $pictureClass = $attributes->class('critter');
@endphp

<picture {{ $pictureClass }}>
    @if ($art['webp'])
        <source type="image/webp" srcset="{{ asset($art['webp']) }}">
    @endif
    <img
        src="{{ asset($art['svg']) }}"
        alt="{{ $decorative ? '' : $art['alt'] }}"
        @if ($decorative) aria-hidden="true" @endif
        width="128"
        height="128"
        loading="lazy"
        decoding="async"
    >
</picture>