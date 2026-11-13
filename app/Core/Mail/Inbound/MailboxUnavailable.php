<?php

namespace App\Core\Mail\Inbound;

use App\Core\Mail\Inbound\Diagnostics\FailureType;
use RuntimeException;

/**
 * The mailbox could not be reached. Nothing is lost: messages stay in the
 * mailbox until the next poll (#579). The failure type (#779) is recorded
 * for the poll; the message never holds server text or credentials.
 */
final class MailboxUnavailable extends RuntimeException
{
    public function __construct(string $message = '', public readonly ?FailureType $type = null)
    {
        parent::__construct($message);
    }
}
