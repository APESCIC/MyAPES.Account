<?php

namespace Plugins\Tickets;

use App\Core\Accounts\User;
use App\Core\Push\PushRoleResolver;
use Plugins\Tickets\Models\SupportTicket;

/**
 * Epic #791 event-to-role push recipients for ticket activity (#797).
 */
final class TicketPushRecipientResolver
{
    public function __construct(
        private readonly PushRoleResolver $pushRoles,
    ) {}

    public function resolveTypeKey(
        User $recipient,
        string $kind,
        SupportTicket $ticket,
        User $actor,
    ): ?string {
        if ($recipient->id === $actor->id) {
            return null;
        }

        if (! $this->matchesKind($recipient, $kind, $ticket)) {
            return null;
        }

        return TicketPushKind::typeKey($kind);
    }

    private function matchesKind(User $recipient, string $kind, SupportTicket $ticket): bool
    {
        $area = TicketsArea::forModule((string) $ticket->sub_core_key);
        $viewAll = $area->permission('view-all');
        $assigneeId = $ticket->assigned_to === null ? null : (int) $ticket->assigned_to;
        $ownerId = $ticket->user_id === null ? null : (int) $ticket->user_id;

        return match ($kind) {
            TicketPushKind::CREATED => $recipient->can($viewAll)
                && $this->pushRoles->forUser($recipient) !== PushRoleResolver::ROLE_SERVICE_USER,
            TicketPushKind::CLIENT_REPLY => $assigneeId !== null
                ? (int) $recipient->id === $assigneeId
                : $recipient->can($viewAll),
            TicketPushKind::STAFF_REPLY => $ownerId !== null && (int) $recipient->id === $ownerId,
            TicketPushKind::ASSIGNED => $assigneeId !== null && (int) $recipient->id === $assigneeId,
            TicketPushKind::INTERNAL_NOTE => $assigneeId !== null
                && (int) $recipient->id === $assigneeId
                && $this->pushRoles->forUser($recipient) !== PushRoleResolver::ROLE_SERVICE_USER,
            TicketPushKind::STATUS_CHANGED => $ownerId !== null && (int) $recipient->id === $ownerId,
            default => false,
        };
    }
}