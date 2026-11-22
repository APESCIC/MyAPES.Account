<?php

namespace Plugins\Tickets;

use App\Core\Accounts\User;

/**
 * Picks one push kind per ticket update (#797).
 */
final class TicketPushKindResolver
{
    public function forCreation(): string
    {
        return TicketPushKind::CREATED;
    }

    public function forInboundReply(User $actor): string
    {
        return $actor->isStaff() ? TicketPushKind::STAFF_REPLY : TicketPushKind::CLIENT_REPLY;
    }

    public function forUpdate(User $actor, TicketUpdatePushContext $context): ?string
    {
        if ($context->internalMessage) {
            return TicketPushKind::INTERNAL_NOTE;
        }

        if ($context->assignmentChanged) {
            return TicketPushKind::ASSIGNED;
        }

        if ($context->publicMessage) {
            return $actor->isStaff() ? TicketPushKind::STAFF_REPLY : TicketPushKind::CLIENT_REPLY;
        }

        if ($context->statusChanged && $context->statusNowTerminal) {
            return TicketPushKind::STATUS_CHANGED;
        }

        return null;
    }
}