<?php

namespace App\Core\Mail\Inbound\Matching;

use RuntimeException;

/**
 * A reply's token, email headers and subject tag point to different
 * tickets or cases (#748), so it is held for staff instead of guessed.
 */
final class ConflictingReferences extends RuntimeException {}
