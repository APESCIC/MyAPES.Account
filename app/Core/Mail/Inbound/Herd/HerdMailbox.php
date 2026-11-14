<?php

namespace App\Core\Mail\Inbound\Herd;

use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\Contracts\MailboxMessage;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\MailboxUnavailable;
use Illuminate\Support\Facades\Cache;
use JsonException;

/**
 * Local reply mailbox via Herd's mail catcher (#816). Reads only mail addressed
 * to the configured inbound mailbox (including plus-addresses), yields oldest
 * first, and deletes each message after the poller has stored it. Handled Herd
 * IDs are remembered so a failed delete cannot be imported twice.
 */
final class HerdMailbox implements InboundMailbox
{
    private const HANDLED_CACHE_KEY = 'inbound-mail:herd-handled-ids';

    private const HANDLED_TTL_SECONDS = 86400;

    /** @var list<string> */
    private array $pendingDeletes = [];

    public function __construct(
        private readonly HerdMailRunner $runner,
        private readonly InboundMailSettings $settings,
    ) {}

    public function messages(int $limit, ?int $afterUid = null): iterable
    {
        $mailbox = $this->settings->mailbox();
        if ($mailbox === null) {
            return;
        }

        $handled = $this->handledIds();
        $listed = $this->listFor($mailbox['local']);
        usort($listed, static fn (array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));

        $yielded = 0;
        foreach ($listed as $summary) {
            $id = (string) ($summary['id'] ?? '');
            if ($id === '' || isset($handled[$id]) || ! $this->isForMailbox($summary, $mailbox['local'], $mailbox['domain'])) {
                continue;
            }

            yield new class($this, $this->runner, $id) implements MailboxMessage
            {
                public function __construct(
                    private readonly HerdMailbox $mailbox,
                    private readonly HerdMailRunner $runner,
                    private readonly string $id,
                ) {}

                public function uid(): ?int
                {
                    return null;
                }

                public function raw(): string
                {
                    return $this->runner->run(['mail:show', $this->id, '--format=raw']);
                }

                public function moveTo(string $folder): void
                {
                    $this->mailbox->markForDeletion($this->id);
                }
            };

            $yielded++;
            if ($yielded >= max(1, $limit)) {
                break;
            }
        }
    }

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
        $handled = $this->handledIds();

        foreach ($ids as $id) {
            $this->runner->run(['mail:delete', $id]);
            $handled[$id] = true;
        }

        $this->rememberHandled($handled);
    }

    public function test(): void
    {
        $local = $this->settings->mailbox()['local'] ?? 'communications';
        $this->runner->run(['mail:list', '--json', '--to='.$local, '--limit=1']);
    }

    public function markForDeletion(string $id): void
    {
        $this->pendingDeletes[] = $id;
        $handled = $this->handledIds();
        $handled[$id] = true;
        $this->rememberHandled($handled);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listFor(string $local): array
    {
        $output = $this->runner->run(['mail:list', '--json', '--to='.$local, '--limit=50']);

        try {
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MailboxUnavailable('Herd mail:list returned invalid JSON.', previous: $exception);
        }

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function isForMailbox(array $message, string $local, string $domain): bool
    {
        $pattern = '/^'.preg_quote($local, '/').'(\+[^@\s]+)?@'.preg_quote($domain, '/').'$/i';

        foreach (['to', 'cc'] as $field) {
            foreach ((array) ($message[$field] ?? []) as $recipient) {
                $address = is_array($recipient) ? (string) ($recipient['address'] ?? '') : (string) $recipient;
                if ($address !== '' && preg_match($pattern, trim($address)) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function handledIds(): array
    {
        $ids = Cache::get(self::HANDLED_CACHE_KEY, []);

        if (! is_array($ids)) {
            return [];
        }

        $handled = [];
        foreach ($ids as $id) {
            $id = is_string($id) || is_int($id) ? (string) $id : '';
            if ($id !== '') {
                $handled[$id] = true;
            }
        }

        return $handled;
    }

    /**
     * @param  array<string, true>  $handled
     */
    private function rememberHandled(array $handled): void
    {
        // Cast to strings so numeric Herd ids are not rehydrated as integers.
        Cache::put(
            self::HANDLED_CACHE_KEY,
            array_map(static fn (string $id): string => $id, array_keys($handled)),
            self::HANDLED_TTL_SECONDS,
        );
    }
}
