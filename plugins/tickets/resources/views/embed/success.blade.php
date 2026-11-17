<x-tickets::embed.layout :parent-origin="$parentOrigin">
    <h1>{{ __('tickets::embed.success_heading') }}</h1>
    <p>{{ __('tickets::embed.success_body', ['reference' => '#'.$ticket->id]) }}</p>
    @if ($form->confirmation_text)
        <p>{{ $form->confirmation_text }}</p>
    @endif
    @push('embed_events')
        post('submitted', { reference: @json('#'.$ticket->id) });
    @endpush
</x-tickets::embed.layout>
