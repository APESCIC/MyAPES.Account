<?php

namespace App\Core\Mail\Inbound\Diagnostics;

use RuntimeException;

/**
 * A mailbox connection failure reduced to its type, so no server text,
 * username or password can travel with it.
 */
final class TransportFailure extends RuntimeException
{
    public function __construct(public readonly FailureType $type)
    {
        parent::__construct($type->value);
    }
}
