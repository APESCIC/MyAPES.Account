<?php

namespace Tests\Fakes;

use App\Core\Mail\Inbound\Diagnostics\FailureType;
use App\Core\Mail\Inbound\Diagnostics\MailboxTransport;
use App\Core\Mail\Inbound\Diagnostics\TransportFailure;

/**
 * A scripted IMAP server for connection tests (#771, #781). Each property
 * makes one step pass or fail with a chosen failure type.
 */
final class FakeImapServer implements MailboxTransport
{
    public bool $resolves = true;

    public ?FailureType $connectFailure = null;

    public ?FailureType $tlsFailure = null;

    /** The greeting is never sent in plain text, as on an SSL/TLS-only port. */
    public bool $plainGreetingTimesOut = false;

    public string $greeting = '* OK [CAPABILITY IMAP4rev1 LITERAL+ SASL-IR LOGIN-REFERRALS ID ENABLE IDLE STARTTLS AUTH=PLAIN] Dovecot ready.';

    public bool $offersStartTls = true;

    public string $loginResponse = 'OK [CAPABILITY IMAP4rev1 IDLE MOVE UIDPLUS SPECIAL-USE] Logged in';

    /** @var list<string> */
    public array $folders = ['INBOX', 'Processed', 'Failed', 'Spam'];

    public int $messages = 3;

    public int $unseen = 1;

    public int $uidValidity = 1700000000;

    public string $certificateName = 'mail.example.test';

    public int $certificateExpiresAt = 1893456000;

    /** @var list<string> commands received, with the LOGIN arguments removed */
    public array $commands = [];

    public bool $connected = false;

    public bool $tls = false;

    /** @var list<string> */
    private array $queue = [];

    private bool $greeted = false;

    public function resolves(string $host): bool
    {
        return $this->resolves && $host !== '';
    }

    public function connect(string $host, int $port, int $timeout): void
    {
        if ($this->connectFailure !== null) {
            throw new TransportFailure($this->connectFailure);
        }

        $this->connected = true;
        $this->greeted = false;
        $this->tls = false;
        $this->queue = [];
    }

    public function startTls(string $peerName, bool $verify): array
    {
        if ($this->tlsFailure !== null) {
            throw new TransportFailure($this->tlsFailure);
        }

        $this->tls = true;

        return ['name' => $this->certificateName, 'expires_at' => $this->certificateExpiresAt];
    }

    public function readLine(): string
    {
        if (! $this->greeted) {
            if (! $this->tls && $this->plainGreetingTimesOut) {
                throw new TransportFailure(FailureType::Timeout);
            }
            $this->greeted = true;

            return $this->greeting;
        }

        return array_shift($this->queue) ?? throw new TransportFailure(FailureType::Timeout);
    }

    public function readBytes(int $length): string
    {
        return '';
    }

    public function writeLine(string $line): void
    {
        [$tag, $command] = array_pad(explode(' ', $line, 2), 2, '');
        $verb = strtoupper(strtok($command, ' ') ?: '');
        $this->commands[] = $verb === 'LOGIN' ? 'LOGIN' : $command;

        $reply = match ($verb) {
            'STARTTLS' => [$this->offersStartTls ? 'OK Begin TLS negotiation now.' : 'BAD Unknown command.'],
            'LOGIN' => [$this->loginResponse],
            'CAPABILITY' => ['* CAPABILITY IMAP4rev1 IDLE MOVE UIDPLUS', 'OK Capability completed.'],
            'STATUS' => $this->status($command),
            'LIST' => [...array_map(static fn (string $folder): string => '* LIST (\HasNoChildren) "." "'.$folder.'"', $this->folders), 'OK List completed.'],
            'LOGOUT' => ['* BYE Logging out', 'OK Logout completed.'],
            default => ['BAD Unknown command.'],
        };

        foreach ($reply as $response) {
            $this->queue[] = str_starts_with($response, '*') ? $response : $tag.' '.$response;
        }
    }

    public function close(): void
    {
        $this->connected = false;
    }

    /**
     * @return list<string>
     */
    private function status(string $command): array
    {
        preg_match('/^STATUS "((?:[^"\\\\]|\\\\.)*)"/i', $command, $match);
        $folder = stripcslashes($match[1] ?? '');

        if (! in_array($folder, $this->folders, true)) {
            return ['NO Mailbox doesn\'t exist: '.$folder];
        }

        return [
            '* STATUS "'.$folder.'" (MESSAGES '.$this->messages.' UNSEEN '.$this->unseen.' UIDVALIDITY '.$this->uidValidity.')',
            'OK Status completed.',
        ];
    }
}
