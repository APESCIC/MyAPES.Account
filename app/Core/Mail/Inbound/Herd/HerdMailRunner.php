<?php

namespace App\Core\Mail\Inbound\Herd;

use App\Core\Mail\Inbound\MailboxUnavailable;

/**
 * Runs `herd mail:*` with a fixed argument list — never a shell string (#816).
 */
interface HerdMailRunner
{
    /**
     * @param  list<string>  $arguments  arguments after `herd`, e.g. ['mail:list', '--json']
     *
     * @throws MailboxUnavailable
     */
    public function run(array $arguments): string;
}
