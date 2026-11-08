@props(['name', 'id', 'label', 'maxFiles', 'maxBytes', 'accept', 'types'])
@php
    $hintId = $id.'-hint';
    $statusId = $id.'-status';
    $size = \App\Core\Attachments\AttachmentLimits::megabytes(intdiv((int) $maxBytes, 1024));
    $fieldErrors = collect($errors->get($name))->merge($errors->get($name.'.*'))->flatten();
@endphp
<div
    {{ $attributes->class(['file-upload']) }}
    data-file-upload
    data-max-files="{{ $maxFiles }}"
    data-max-bytes="{{ $maxBytes }}"
    data-accept="{{ $accept }}"
    data-label-type="{{ __('attachments.component.rejected_type', ['types' => $types]) }}"
    data-label-size="{{ __('attachments.component.rejected_size') }}"
    data-label-count="{{ __('attachments.component.rejected_count') }}"
    data-label-total="{{ __('attachments.component.rejected_total') }}"
    data-label-remove="{{ __('attachments.component.remove') }}"
    data-label-selected="{{ __('attachments.component.selected') }}"
>
    <label for="{{ $id }}" class="file-upload__label">{{ $label }}</label>
    <p id="{{ $hintId }}" class="muted file-upload__hint">
        {{ __('attachments.component.limits', ['max' => $maxFiles, 'size' => $size, 'types' => $types]) }}
    </p>
    <div class="file-upload__drop" data-file-upload-drop>
        <i data-lucide="upload" aria-hidden="true"></i>
        <span aria-hidden="true">{{ __('attachments.component.drop_or_choose') }}</span>
        <input
            id="{{ $id }}"
            name="{{ $name }}[]"
            type="file"
            accept="{{ $accept }}"
            multiple
            aria-describedby="{{ $hintId }} {{ $statusId }}"
            @if($fieldErrors->isNotEmpty()) aria-invalid="true" @endif
        >
    </div>
    <ul class="file-upload__list" data-file-upload-list role="list"></ul>
    <p id="{{ $statusId }}" class="visually-hidden" role="status" aria-live="polite" data-file-upload-status></p>
    @foreach($fieldErrors as $message)
        <p class="sg-field__error">{{ $message }}</p>
    @endforeach
</div>
