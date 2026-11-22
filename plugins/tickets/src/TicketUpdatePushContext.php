<?php

namespace Plugins\Tickets;

/**
 * Facts from a ticket update action used to choose a push kind (#797).
 */
final readonly class TicketUpdatePushContext
{
    public function __construct(
        public bool $publicMessage = false,
        public bool $internalMessage = false,
        public bool $assignmentChanged = false,
        public bool $statusChanged = false,
        public bool $statusNowTerminal = false,
    ) {}
}