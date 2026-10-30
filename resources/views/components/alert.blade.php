@props(['tone' => 'info', 'title' => null])
@php(in_array($tone, ['ok', 'warning', 'error', 'info'], true) || throw new InvalidArgumentException("Unknown alert tone [{$tone}]."))
@php($icon = $tone === 'ok' ? 'circle-check-big' : ($tone === 'warning' ? 'circle-pause' : 'circle-help'))
<div {{ $attributes->class(['sg-alert', 'sg-alert-'.$tone]) }} role="{{ $tone === 'error' ? 'alert' : 'status' }}">
    <i data-lucide="{{ $icon }}" aria-hidden="true"></i>
    <div>
        @if($title)
            <p><strong>{{ $title }}</strong></p>
        @endif
        {{ $slot }}
    </div>
</div>
