@props(['value' => null])
<div {{ $attributes->class(['rich-text']) }}>{{ app(\App\Core\Content\RichText::class)->render($value) }}</div>
