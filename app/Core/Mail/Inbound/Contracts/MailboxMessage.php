<?php

namespace App\Core\Mail\Inbound\Contracts;

/**
 * One message waiting in the inbound mailbox (#579).
 */
interface MailboxMessage
{
    public function raw(): string;

    /** Mark the message for deletion once it is safely stored. */
    public function delete(): void;
}
