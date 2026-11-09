<?php

namespace App\Core\Mail\Inbound;

use RuntimeException;

/**
 * The mailbox could not be reached. Nothing is lost: messages stay in the
 * mailbox until the next poll (#579).
 */
final class MailboxUnavailable extends RuntimeException {}
