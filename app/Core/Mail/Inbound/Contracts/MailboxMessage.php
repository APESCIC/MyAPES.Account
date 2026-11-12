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

    /** Mark the message for deletion once it is safely stored. */
    public function delete(): void;
}
