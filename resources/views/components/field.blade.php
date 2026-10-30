@props(['label', 'name', 'id' => null, 'type' => 'text', 'hint' => null, 'value' => null])
@php
    $fieldId = $id ?? 'field-'.\Illuminate\Support\Str::slug($name);
    $hintId = $hint ? $fieldId.'-hint' : '';
    $errorId = $fieldId.'-error';
@endphp
<div class="sg-field">
    <label for="{{ $fieldId }}" class="sg-field__label">{{ $label }}</label>
    @if($slot->isNotEmpty())
        {{ $slot }}
    @elseif($type === 'textarea')
        <textarea
            id="{{ $fieldId }}"
            name="{{ $name }}"
            @error($name) aria-invalid="true" aria-describedby="{{ trim($hintId.' '.$errorId) }}" @else @if($hintId !== '') aria-describedby="{{ $hintId }}" @endif @enderror
            {{ $attributes }}
        >{{ old($name, $value) }}</textarea>
    @else
        <input
            id="{{ $fieldId }}"
            type="{{ $type }}"
            name="{{ $name }}"
            value="{{ old($name, $value) }}"
            @error($name) aria-invalid="true" aria-describedby="{{ trim($hintId.' '.$errorId) }}" @else @if($hintId !== '') aria-describedby="{{ $hintId }}" @endif @enderror
            {{ $attributes }}
        >
    @endif
    @if($hint)
        <p id="{{ $hintId }}" class="sg-field__hint">{{ $hint }}</p>
    @endif
    @error($name)
        <p id="{{ $errorId }}" class="sg-field__error">{{ $message }}</p>
    @enderror
</div>
