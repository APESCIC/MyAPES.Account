<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\Contracts\MailboxMessage;
use App\Core\Mail\Inbound\MailboxUnavailable;
use RuntimeException;

/**
 * In-memory mailbox for poller tests (#579). A message's UID is its index
 * plus one (#777).
 */
final class FakeMailbox implements InboundMailbox
{
    /** @var array<int, string|null> raw messages by index; null makes raw() fail */
    public array $messages = [];

    /** @var array<int, bool> */
    public array $deleted = [];

    public bool $expunged = false;

    public bool $unavailable = false;

    public ?int $uidValidity = 1;

    /** @var list<int|null> the each poll asked for */
    public array $requestedAfter = [];

    /** @var list<int> UIDs handed to the poller */
    public array $fetchedUids = [];

    private int $next = 0;

    public function add(?string $raw): int
    {
        $index = $this->next++;
        $this->messages[$index] = $raw;

        return $index + 1;
    }

    public function messages(int $limit, ?int $afterUid = null): iterable
    {
        if ($this->unavailable) {
            throw new MailboxUnavailable('Connection refused.');
        }

        $this->requestedAfter[] = $afterUid;
        $waiting = array_filter($this->messages, static fn (int $index): bool => $afterUid === null || $index + 1 > $afterUid, ARRAY_FILTER_USE_KEY);

        foreach (array_slice($waiting, 0, $limit, true) as $index => $raw) {
            $this->fetchedUids[] = $index + 1;

            yield new class($this, $index, $raw) implements MailboxMessage
            {
                public function __construct(
                    private readonly FakeMailbox $mailbox,
                    private readonly int $index,
                    private readonly ?string $raw,
                ) {}

                public function uid(): ?int
                {
                    return $this->index + 1;
                }

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

    public function uidValidity(): ?int
    {
        if ($this->unavailable) {
            throw new MailboxUnavailable('Connection refused.');
        }

        return $this->uidValidity;
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
