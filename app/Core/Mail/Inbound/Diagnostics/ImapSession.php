<?php

namespace App\Core\Mail\Inbound\Diagnostics;

/**
 * Tagged IMAP commands over a MailboxTransport (#771). Nothing here logs:
 * the LOGIN line holds the password.
 */
final class ImapSession
{
    private const MAX_LINES = 5000;

    private int $tag = 0;

    public function __construct(private readonly MailboxTransport $transport) {}

    public function greeting(): string
    {
        return $this->transport->readLine();
    }

    public function command(string $command): ImapResponse
    {
        $tag = sprintf('A%03d', ++$this->tag);
        $this->transport->writeLine($tag.' '.$command);

        $untagged = [];
        for ($i = 0; $i < self::MAX_LINES; $i++) {
            $line = $this->fullLine();

            if (str_starts_with($line, $tag.' ')) {
                [$status, $text] = array_pad(explode(' ', substr($line, strlen($tag) + 1), 2), 2, '');

                return new ImapResponse(strtoupper($status), $text, $untagged);
            }

            if (str_starts_with($line, '+')) {
                return new ImapResponse('BAD', '', $untagged);
            }

            $untagged[] = $line;
        }

        return new ImapResponse('BAD', '', $untagged);
    }

    public static function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }

    /**
     * Reads one response line, folding any {n} literal into a quoted string.
     */
    private function fullLine(): string
    {
        $line = $this->transport->readLine();

        while (preg_match('/\{(\d+)\}$/', $line, $literal) === 1) {
            $line = substr($line, 0, -strlen($literal[0]))
                .self::quote($this->transport->readBytes((int) $literal[1]))
                .$this->transport->readLine();
        }

        return $line;
    }
}
