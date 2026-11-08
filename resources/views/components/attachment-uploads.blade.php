@props(['idPrefix', 'legend' => null])
@php
    $limits = \App\Core\Attachments\AttachmentLimits::forComponent();
    $total = \App\Core\Attachments\AttachmentLimits::megabytes(\App\Core\Attachments\AttachmentLimits::SUBMISSION_MAX_KB);
    $groupErrors = \App\Core\Attachments\AttachmentLimits::fieldErrors($errors, 'attachments', 'screencast');
@endphp
<fieldset
    {{ $attributes->class(['attachment-uploads']) }}
    data-file-upload-group
    data-max-total-bytes="{{ \App\Core\Attachments\AttachmentLimits::SUBMISSION_MAX_KB * 1024 }}"
>
    <legend>{{ $legend ?? __('attachments.component.legend') }}</legend>
    <p class="muted">{{ __('attachments.component.total_limit', ['size' => $total]) }}</p>
    <x-file-upload
        name="screenshots"
        :id="$idPrefix.'-screenshots'"
        :label="__('attachments.component.screenshots')"
        :max-files="$limits['screenshots']['maxFiles']"
        :max-bytes="$limits['screenshots']['maxBytes']"
        :accept="$limits['screenshots']['accept']"
        :types="__('attachments.type_lists.screenshots')"
    />
    <x-file-upload
        name="screencasts"
        :id="$idPrefix.'-screencasts'"
        :label="__('attachments.component.screencasts')"
        :max-files="$limits['screencasts']['maxFiles']"
        :max-bytes="$limits['screencasts']['maxBytes']"
        :accept="$limits['screencasts']['accept']"
        :types="__('attachments.type_lists.screencasts')"
    />
    <x-file-upload
        name="documents"
        :id="$idPrefix.'-documents'"
        :label="__('attachments.component.documents')"
        :max-files="$limits['documents']['maxFiles']"
        :max-bytes="$limits['documents']['maxBytes']"
        :accept="$limits['documents']['accept']"
        :types="__('attachments.type_lists.documents')"
    />
    @foreach($groupErrors as $message)
        <p class="sg-field__error">{{ $message }}</p>
    @endforeach
    <p class="muted" role="status" aria-live="assertive" data-file-upload-group-status data-label-failed="{{ __('attachments.component.upload_failed') }}"></p>
</fieldset>
