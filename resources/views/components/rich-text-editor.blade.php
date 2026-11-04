@props(['name', 'id' => null, 'value' => null, 'required' => false, 'maxlength' => null])
@php($apiKey = config('services.tinymce.api_key'))
@php($editorEnabled = is_string($apiKey) && $apiKey !== '')
@if($editorEnabled)
    @once
        @push('head')
            <script src="https://cdn.tiny.cloud/1/{{ rawurlencode($apiKey) }}/tinymce/8/tinymce.min.js" referrerpolicy="origin" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
        @endpush
    @endonce
@endif
<textarea
    id="{{ $id ?? $name }}"
    name="{{ $name }}"
    @if($editorEnabled) data-rich-text @endif
    @if($maxlength) maxlength="{{ $maxlength }}" @endif
    @required($required)
    {{ $attributes }}
>{{ $editorEnabled ? app(\App\Core\Content\RichText::class)->forEditor($value) : $value }}</textarea>
