@props(['attachments'])
@if($attachments->isNotEmpty())
    <ul {{ $attributes->class(['attachment-list']) }} role="list">
        @foreach($attachments as $attachment)
            <li class="attachment-item" data-attachment-kind="{{ $attachment->kind }}">
                <i data-lucide="{{ $attachment->icon() }}" aria-hidden="true"></i>
                <span class="visually-hidden">{{ __('attachments.kinds.'.$attachment->kind) }}:</span>
                <a href="{{ route('support.attachments.download', $attachment) }}">{{ $attachment->original_name }}</a>
                <span class="muted">({{ $attachment->humanSize() }})</span>
            </li>
        @endforeach
    </ul>
@endif
