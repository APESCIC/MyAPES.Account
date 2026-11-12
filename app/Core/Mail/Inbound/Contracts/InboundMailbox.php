<?php

namespace App\Core\Mail\Inbound\Contracts;

use App\Core\Mail\Inbound\MailboxUnavailable;

/**
 * The mailbox replies arrive in (#579).
 */
interface InboundMailbox
{
    /**
     * Oldest messages first.
     *
     * @return iterable<int, MailboxMessage>
     *
     * @throws MailboxUnavailable
     */
    public function messages(int $limit): iterable;

    /**
     * Permanently remove messages marked for deletion.
     *
     * @throws MailboxUnavailable
     */
    public function expunge(): void;

    /**
     * Connects and opens the folder without reading or changing any mail (#736).
     *
     * @throws MailboxUnavailable
     */
    public function test(): void;
}
