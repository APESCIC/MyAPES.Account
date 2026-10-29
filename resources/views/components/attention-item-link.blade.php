@props([
    'href',
    'type',
    'icon',
    'kicker',
    'title',
    'status',
    'updatedAt',
    'priority' => null,
    'context' => null,
    'owner' => null,
])

@php
    $updated = \Illuminate\Support\Carbon::instance($updatedAt);
@endphp

<a {{ $attributes->merge(['class' => 'attention-item attention-item--'.$type, 'href' => $href]) }}>
    <span class="attention-item__icon" aria-hidden="true">
        <i data-lucide="{{ $icon }}"></i>
    </span>
    <span class="attention-item__body">
        <span class="attention-item__kicker">{{ $kicker }}</span>
        <strong>{{ $title }}</strong>
        <span class="attention-item__detail">
            <span>{{ \App\Support\WorkflowLabels::status($status) }}</span>
            @if($priority)
                <span aria-hidden="true">•</span>
                <span>Priority: <b class="priority priority--{{ $priority }}">{{ \App\Support\WorkflowLabels::priority($priority) }}</b></span>
            @elseif($context)
                <span aria-hidden="true">•</span>
                <span>{{ $context }}</span>
            @endif
        </span>
    </span>
    <span class="attention-item__meta">
        <time datetime="{{ $updated->toAtomString() }}" title="{{ $updated->format('j F Y, H:i') }}">
            {{ $updated->diffForHumans() }}
        </time>
        @if($owner)
            <span>{{ $owner }}</span>
        @endif
    </span>
    <i data-lucide="chevron-right" class="attention-item__chevron" aria-hidden="true"></i>
</a>
