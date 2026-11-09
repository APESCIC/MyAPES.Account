<?php

namespace Plugins\Tickets;

use App\Core\Accounts\User;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;

/**
 * Emails staff and the ticket owner about a change (#589). Shared by the
 * ticket pages and replies by email.
 */
final class TicketStakeholderNotifier
{
    public function __construct(
        private readonly TicketServiceConfiguration $ticketServices,
    ) {}

    public function notify(
        SupportTicket $ticket,
        User $actor,
        string $eventLabel,
        bool $staffOnly = false,
        int $attachmentCount = 0,
    ): void {
        $subCoreKey = (string) $ticket->sub_core_key;
        $area = TicketsArea::forModule($subCoreKey);
        $ticketService = $this->ticketServices->for($subCoreKey);
        $recipients = User::query()
            ->eligibleStaff()
            ->withAuthorizationPermission($area->permission('view-all'))
            ->get();

        if (! $staffOnly && $ticket->user?->can('view', $ticket)) {
            $recipients->push($ticket->user);
        }

        $recipients
            ->unique('id')
            ->reject(fn (User $recipient): bool => $recipient->id === $actor->id)
            ->each(fn (User $recipient) => $recipient->notify(new TicketUpdatedNotification(
                $ticket,
                $actor,
                $eventLabel,
                $subCoreKey,
                $area->showRouteName(),
                $ticketService->serviceName,
                $attachmentCount,
            )));
    }
}
