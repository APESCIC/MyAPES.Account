@props(['as' => 'section', 'flat' => false])
<{{ $as }} {{ $attributes->class(['panel', 'panel-flat' => $flat]) }}>
    {{ $slot }}
</{{ $as }}>
