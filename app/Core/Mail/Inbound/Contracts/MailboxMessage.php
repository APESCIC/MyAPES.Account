<?php

namespace App\Core\Mail\Inbound\Contracts;

/**
 * One message waiting in the inbound mailbox (#579).
 */
interface MailboxMessage
{
    /** The IMAP UID (#777), or null for a mailbox without UIDs such as Mailpit. */
    public function uid(): ?int;

    public function raw(): string;

    /**
     * Moves the message out of the folder being read, for example to Processed (#776).
     * Takes effect once the mailbox is expunged.
     */
    public function moveTo(string $folder): void;
}
