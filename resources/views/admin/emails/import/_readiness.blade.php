{{-- Readiness checklist (#775): a tick or a cross, and who fixes each cross. --}}
<section class="panel" aria-labelledby="import-readiness-heading" data-import-readiness>
    <h2 id="import-readiness-heading">{{ __('admin.emails.import.readiness.heading') }}</h2>
    <ul class="detail-list">
        @foreach($readiness as $item)
            <li data-readiness="{{ $item['key'] }}" data-readiness-state="{{ $item['ok'] ? 'ok' : 'missing' }}">
                <span aria-hidden="true">{{ $item['ok'] ? '✓' : '✗' }}</span>
                <span class="visually-hidden">{{ $item['ok'] ? __('admin.emails.import.readiness.ok') : __('admin.emails.import.readiness.missing') }}:</span>
                {{ $item['message'] }}
                @unless($item['ok'])
                    <span class="muted" data-fixed-by="{{ $item['fixed_by'] }}">{{ __("admin.emails.import.readiness.fixed_by.{$item['fixed_by']}") }}</span>
                @endunless
            </li>
        @endforeach
    </ul>
</section>
