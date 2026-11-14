<?php

namespace App\Core\Mail\Inbound\Herd;

use App\Core\Mail\Inbound\MailboxUnavailable;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Invokes the `herd` binary with argv only (#816).
 */
final class ProcessHerdMailRunner implements HerdMailRunner
{
    private const TIMEOUT_SECONDS = 30;

    public function run(array $arguments): string
    {
        $process = new Process(['herd', ...$arguments]);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException $exception) {
            throw new MailboxUnavailable('Herd mail timed out.', previous: $exception);
        } catch (ProcessFailedException $exception) {
            throw new MailboxUnavailable(
                'Herd mail failed: '.trim($process->getErrorOutput() !== '' ? $process->getErrorOutput() : $process->getOutput()),
                previous: $exception,
            );
        }

        return $process->getOutput();
    }
}
