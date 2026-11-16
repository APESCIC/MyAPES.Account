@php
    $noteStoreRoute = $noteStoreRoute ?? 'clients.notes.store';
    $noteSubject = $noteSubject ?? ($managedUser ?? $client);
    $adminHub = $isAdminHub ?? false;
@endphp
<section class="panel" aria-labelledby="hub-notes-title" data-hub-notes>
    <h2 id="hub-notes-title">{{ __('admin.users.hub.notes.heading') }}</h2>
    <p class="muted">{{ __('admin.users.hub.notes.intro') }}</p>

    @can('create', App\Core\Accounts\ClientNote::class)
        <form method="post" action="{{ route($noteStoreRoute, $noteSubject) }}">
            @csrf
            @if($adminHub)
                <input type="hidden" name="admin_hub" value="1">
            @endif
            <label for="client-note-body">{{ __('admin.users.hub.notes.body') }}</label>
            <textarea id="client-note-body" name="body" required maxlength="5000">{{ old('body') }}</textarea>
            <div class="actions"><button type="submit">{{ __('admin.users.hub.notes.add') }}</button></div>
        </form>
    @endcan

    @forelse($hubNotes as $note)
        <article class="stack-spaced" data-client-note="{{ $note->id }}">
            <p class="muted">
                {{ __('admin.users.hub.notes.by', ['author' => $note->author?->name ?? __('admin.users.system')]) }}
                · {{ $note->created_at }}
            </p>
            @can('update', $note)
                <form method="post" action="{{ route('clients.notes.update', [$noteSubject, $note]) }}">
                    @csrf
                    @method('put')
                    @if($adminHub)
                        <input type="hidden" name="admin_hub" value="1">
                    @endif
                    <label for="client-note-body-{{ $note->id }}">{{ __('admin.users.hub.notes.body') }}</label>
                    <textarea id="client-note-body-{{ $note->id }}" name="body" required maxlength="5000">{{ old('body_'.$note->id, $note->body) }}</textarea>
                    <div class="actions">
                        <button type="submit">{{ __('admin.users.hub.notes.edit') }}</button>
                    </div>
                </form>
                <form method="post" action="{{ route('clients.notes.destroy', [$noteSubject, $note]) }}">
                    @csrf
                    @method('delete')
                    @if($adminHub)
                        <input type="hidden" name="admin_hub" value="1">
                    @endif
                    <button class="danger-btn" type="submit">{{ __('admin.users.hub.notes.delete') }}</button>
                </form>
            @else
                <p>{{ $note->body }}</p>
            @endcan
        </article>
    @empty
        <p class="muted">{{ __('admin.users.hub.notes.empty') }}</p>
    @endforelse
</section>
