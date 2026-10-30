@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'type' => 'submit', 'icon' => null])
@php
    $variantClass = match ($variant) {
        'primary' => 'sg-btn-primary',
        'secondary' => 'sg-btn-secondary',
        'ghost' => 'sg-btn-ghost',
        'danger' => 'sg-btn-danger',
        default => throw new InvalidArgumentException("Unknown button variant [{$variant}]."),
    };
    $classes = ['sg-btn', $variantClass, 'sg-btn-sm' => $size === 'sm'];
@endphp
@if($href !== null)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if($icon)<i data-lucide="{{ $icon }}" aria-hidden="true"></i>@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>
        @if($icon)<i data-lucide="{{ $icon }}" aria-hidden="true"></i>@endif
        {{ $slot }}
    </button>
@endif
