@props(['tone' => 'neutral'])
@php(in_array($tone, ['ok', 'warning', 'error', 'info', 'neutral'], true) || throw new InvalidArgumentException("Unknown badge tone [{$tone}]."))
<span {{ $attributes->class(['sg-badge', 'sg-badge-'.$tone]) }}>{{ $slot }}</span>
