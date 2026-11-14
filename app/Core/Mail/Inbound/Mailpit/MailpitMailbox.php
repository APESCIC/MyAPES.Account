<?php

namespace App\Core\Mail\Inbound\Mailpit;

use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\Contracts\MailboxMessage;
use App\Core\Mail\Inbound\InboundMailSettings;

/**
 * The local reply mailbox (#756): reads replies sent to INBOUND_MAIL_ADDRESS
 * (and its +token forms) from a local Mailpit, oldest first. Messages are
 * deleted from Mailpit only after the poller has stored them.
 */
final class MailpitMailbox implements InboundMailbox
{
    /** @var list<string> */
    private array $pendingDeletes = [];

    public function __construct(
        private readonly MailpitClient $client,
        private readonly InboundMailSettings $settings,
    ) {}

    public function messages(int $limit, ?int $afterUid = null): iterable
    {
        $mailbox = $this->settings->mailbox();
        if ($mailbox === null) {
            return;
        }

        $addressed = array_filter(
            $this->client->search('to:'.$mailbox['local']),
            fn (array $message): bool => $this->isForMailbox($message, $mailbox['local'], $mailbox['domain']),
        );
        usort($addressed, fn (array $a, array $b): int => strcmp((string) ($a['Created'] ?? ''), (string) ($b['Created'] ?? '')));

        foreach (array_slice($addressed, 0, max(1, $limit)) as $message) {
            $id = (string) ($message['ID'] ?? '');
            if ($id === '') {
                continue;
            }

            yield new class($this, $this->client, $id) implements MailboxMessage
            {
                public function __construct(
                    private readonly MailpitMailbox $mailbox,
                    private readonly MailpitClient $client,
                    private readonly string $id,
                ) {}

                public function uid(): ?int
                {
                    return null;
                }

                public function raw(): string
                {
                    return $this->client->raw($this->id);
                }

                /**
                 * Mailpit has no folders: a handled message is simply deleted.
                 */
                public function moveTo(string $folder): void
                {
                    $this->mailbox->markForDeletion($this->id);
                }
            };
        }
    }

    /**
     * Mailpit has no UIDs; duplicates are skipped by Message-ID.
     */
    public function uidValidity(): ?int
    {
        return null;
    }

    public function ensureFolders(array $folders): void {}

    public function purge(string $folder, int $olderThanDays): int
    {
        return 0;
    }

    public function expunge(): void
    {
        $ids = $this->pendingDeletes;
        $this->pendingDeletes = [];

        $this->client->delete($ids);
    }

    public function test(): void
    {
        $this->client->search('to:'.($this->settings->mailbox()['local'] ?? 'communications'), 1);
    }

    public function markForDeletion(string $id): void
    {
        $this->pendingDeletes[] = $id;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function isForMailbox(array $message, string $local, string $domain): bool
    {
        $pattern = '/^'.preg_quote($local, '/').'(\+[^@\s]+)?@'.preg_quote($domain, '/').'$/i';

        foreach (['To', 'Cc'] as $field) {
            foreach ((array) ($message[$field] ?? []) as $recipient) {
                if (is_array($recipient) && preg_match($pattern, trim((string) ($recipient['Address'] ?? ''))) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
