@props(['attachments'])
@if($attachments->isNotEmpty())
    <ul {{ $attributes->class(['attachment-list']) }} role="list">
        @foreach($attachments as $attachment)
            <li class="attachment-item" data-attachment-kind="{{ $attachment->kind }}">
                <i data-lucide="{{ $attachment->icon() }}" aria-hidden="true"></i>
                <span class="visually-hidden">{{ $attachment->kindLabel() }}:</span>
                <a href="{{ route('support.attachments.download', $attachment) }}">{{ $attachment->original_name }}</a>
                <span class="muted">({{ $attachment->humanSize() }})</span>
                @if($attachment->isInternal())
                    <x-badge tone="info">{{ __('attachments.list.staff_only') }}</x-badge>
                @endif
                @can('delete', $attachment)
                    <form method="post" action="{{ route('support.attachments.destroy', $attachment) }}" data-confirm="{{ __('attachments.list.confirm_delete') }}">
                        @csrf
                        @method('delete')
                        <button type="submit" class="button-secondary file-upload__remove" aria-label="{{ __('attachments.list.delete_named', ['name' => $attachment->original_name]) }}">
                            <i data-lucide="trash-2" aria-hidden="true"></i>
                            <span class="visually-hidden">{{ __('attachments.list.delete') }}</span>
                        </button>
                    </form>
                @endcan
            </li>
        @endforeach
    </ul>
@endif
