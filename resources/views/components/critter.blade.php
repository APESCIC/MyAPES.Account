@props([
    'name',
    'pose' => 'welcome',
    'decorative' => false,
])

@php
    $art = \App\Support\Theme\CritterCast::resolve($name, $pose);
    $imgClass = $attributes->get('class');
    $pictureClass = $attributes->except('class');
@endphp

<picture {{ $pictureClass->class($imgClass) }}>
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