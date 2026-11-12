<?php

namespace Plugins\Tickets\Http\Controllers\Api;

use App\Core\Api\ApiScope;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Models\SupportTicketMessage;

/**
 * Tickets for APES Newsroom and EASM (#640): tickets:read, limited to the
 * client's organisation. Staff notes are never returned.
 */
class TicketApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organisation' => ['nullable', 'string', 'max:32'],
            'category' => ['nullable', 'string', 'max:64'],
        ]);
        $client = ApiScope::client($request);

        if (($validated['organisation'] ?? $client->organisation) !== $client->organisation) {
            return response()->json(['message' => 'This API client cannot read tickets for that organisation.'], 403);
        }

        $tickets = $this->tickets($request)
            ->when($validated['category'] ?? null, static fn (Builder $query, string $category) => $query->where('service_area', $category))
            ->with('user')
            ->latest('id')
            ->paginate(ApiScope::perPage($request))
            ->withQueryString();

        return response()->json([
            'data' => $tickets->getCollection()->map(fn (SupportTicket $ticket): array => $this->present($ticket))->values(),
            'meta' => ['current_page' => $tickets->currentPage(), 'last_page' => $tickets->lastPage(), 'per_page' => $tickets->perPage(), 'total' => $tickets->total()],
        ]);
    }

    public function show(Request $request, int $ref): JsonResponse
    {
        $ticket = $this->tickets($request)->with(['user', 'messages' => static fn ($query) => $query->where('is_staff_note', false)->with('user')->oldest('id')])->findOrFail($ref);

        return response()->json(['data' => [
            ...$this->present($ticket),
            'messages' => $ticket->messages->map(static fn (SupportTicketMessage $message): array => [
                'id' => $message->id,
                'from' => $message->user_id === $ticket->user_id ? 'requester' : 'staff',
                'body' => $message->message,
                'via_email' => (bool) $message->via_email,
                'created_at' => $message->created_at?->toIso8601String(),
            ])->values(),
        ]]);
    }

    /**
     * @return Builder<SupportTicket>
     */
    private function tickets(Request $request): Builder
    {
        return SupportTicket::query()->forSubCore(ApiScope::client($request)->organisation);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'reference' => 'Ticket #'.$ticket->id,
            'organisation' => $ticket->sub_core_key,
            'category' => $ticket->service_area,
            'sub_category' => $ticket->sub_category,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'requester' => $ticket->user === null ? null : ['id' => $ticket->user->id, 'name' => $ticket->user->name],
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
        ];
    }
}
