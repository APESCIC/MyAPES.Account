<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\Contracts\MailboxMessage;
use App\Core\Mail\Inbound\MailboxUnavailable;
use RuntimeException;

/**
 * In-memory mailbox for poller tests (#579).
 */
final class FakeMailbox implements InboundMailbox
{
    /** @var array<int, string|null> raw messages; null makes raw() fail */
    public array $messages = [];

    /** @var array<int, bool> */
    public array $deleted = [];

    public bool $expunged = false;

    public bool $unavailable = false;

    public function add(?string $raw): void
    {
        $this->messages[] = $raw;
    }

    public function messages(int $limit): iterable
    {
        if ($this->unavailable) {
            throw new MailboxUnavailable('Connection refused.');
        }

        foreach (array_slice($this->messages, 0, $limit, true) as $index => $raw) {
            yield new class($this, $index, $raw) implements MailboxMessage
            {
                public function __construct(
                    private readonly FakeMailbox $mailbox,
                    private readonly int $index,
                    private readonly ?string $raw,
                ) {}

                public function raw(): string
                {
                    return $this->raw ?? throw new RuntimeException('Fetch failed.');
                }

                public function delete(): void
                {
                    $this->mailbox->deleted[$this->index] = true;
                }
            };
        }
    }

    public function test(): void
    {
        if ($this->unavailable) {
            throw new MailboxUnavailable('The inbound mailbox could not be reached (ConnectionFailedException).');
        }
    }

    public function expunge(): void
    {
        $this->expunged = true;

        foreach (array_keys($this->deleted) as $index) {
            unset($this->messages[$index]);
        }

        $this->deleted = [];
    }
}
