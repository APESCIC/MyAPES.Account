<x-tickets::embed.layout :parent-origin="$parentOrigin">
    <x-critter name="rio" pose="welcome" class="brand-mascot" decorative />
    <h1>{{ __('tickets::embed.success_heading') }}</h1>
    <p>{{ __('tickets::embed.success_body', ['reference' => '#'.$ticket->id]) }}</p>
    @if ($form->confirmation_text)
        <p>{{ $form->confirmation_text }}</p>
    @endif
    <div hidden data-embed-submitted data-reference="{{ '#'.$ticket->id }}"></div>
    @push('embed_events')
        var submitted = document.querySelector('[data-embed-submitted]');
        if (submitted) {
            post('submitted', { reference: submitted.getAttribute('data-reference') });
        }
    @endpush
</x-tickets::embed.layout>
