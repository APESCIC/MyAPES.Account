<?php

namespace App\Http\Controllers\Staff;

use App\Core\Accounts\ClientNote;
use App\Core\Accounts\User;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\AuthorizationProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientNoteController extends Controller
{
    public function __construct(
        private readonly AuthorizationProfile $profile,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function store(Request $request, User $client): RedirectResponse
    {
        $this->assertPublicClient($client);
        Gate::authorize('create', ClientNote::class);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $note = ClientNote::query()->create([
            'user_id' => $client->id,
            'author_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        $this->auditLogger->record('client_note.created', $request->user(), $client, [
            'note_id' => $note->id,
            'target_user_id' => $client->id,
            'actor_id' => $request->user()->id,
        ]);

        return $this->redirectBack($request, $client)
            ->with('status', __('admin.users.hub.notes.created'));
    }

    public function update(Request $request, User $client, ClientNote $note): RedirectResponse
    {
        $this->assertPublicClient($client);
        abort_unless((int) $note->user_id === (int) $client->id, 404);
        Gate::authorize('update', $note);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $note->update(['body' => $validated['body']]);

        $this->auditLogger->record('client_note.updated', $request->user(), $client, [
            'note_id' => $note->id,
            'target_user_id' => $client->id,
            'actor_id' => $request->user()->id,
        ]);

        return $this->redirectBack($request, $client)
            ->with('status', __('admin.users.hub.notes.updated'));
    }

    public function destroy(Request $request, User $client, ClientNote $note): RedirectResponse
    {
        $this->assertPublicClient($client);
        abort_unless((int) $note->user_id === (int) $client->id, 404);
        Gate::authorize('delete', $note);

        $noteId = $note->id;
        $note->delete();

        $this->auditLogger->record('client_note.deleted', $request->user(), $client, [
            'note_id' => $noteId,
            'target_user_id' => $client->id,
            'actor_id' => $request->user()->id,
        ]);

        return $this->redirectBack($request, $client)
            ->with('status', __('admin.users.hub.notes.deleted'));
    }

    private function assertPublicClient(User $client): void
    {
        abort_if($this->profile->hasDirectoryProtectedEligibility($client), 404);
    }

    private function redirectBack(Request $request, User $client): RedirectResponse
    {
        if ($request->user()?->can('admin.users.view') && $request->boolean('admin_hub')) {
            return redirect()->route('admin.users.show', [
                'user' => $client,
                'tab' => 'overview',
            ]);
        }

        return redirect()->route('clients.show', [
            'client' => $client,
            'tab' => 'overview',
        ]);
    }
}
