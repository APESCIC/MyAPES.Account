<?php

namespace App\Core\Mail\Inbound;

use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\Contracts\MailboxMessage;
use App\Core\Mail\Inbound\Diagnostics\FailureType;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/**
 * The communications mailbox over IMAPS (#579). Credentials come from the
 * environment or Admin → Emails → Import (#737, #774) and never appear in
 * errors or logs. Imported mail moves to Processed or Failed (#776).
 */
final class ImapMailbox implements InboundMailbox
{
    private ?Client $client = null;

    private ?Folder $folder = null;

    private ?bool $supportsMove = null;

    public function __construct(private readonly InboundMailSettings $settings) {}

    public function messages(int $limit, ?int $afterUid = null): iterable
    {
        try {
            $query = $this->folder()->query()->setFetchOrder('asc')->limit($limit);
            $messages = $afterUid !== null ? $query->getByUidGreater($afterUid) : $query->whereAll()->get();
        } catch (Throwable $exception) {
            throw self::unavailable('The inbound mailbox could not be read', $exception);
        }

        foreach ($messages as $message) {
            yield new class($this, $message) implements MailboxMessage
            {
                public function __construct(
                    private readonly ImapMailbox $mailbox,
                    private readonly Message $message,
                ) {}

                public function uid(): ?int
                {
                    $uid = $this->message->getUid();

                    return is_numeric($uid) ? (int) $uid : null;
                }

                public function raw(): string
                {
                    return rtrim((string) $this->message->getHeader()?->raw)."\r\n\r\n".$this->message->getRawBody();
                }

                public function moveTo(string $folder): void
                {
                    $this->mailbox->move($this->message, $folder);
                }
            };
        }
    }

    /**
     * IMAP MOVE when the server has it; otherwise COPY, then delete once the copy is made (#776).
     */
    public function move(Message $message, string $folder): void
    {
        try {
            if ($this->supportsMove()) {
                $message->move($folder);

                return;
            }

            if ($message->copy($folder) !== null) {
                $message->delete(expunge: false);
            }
        } catch (Throwable $exception) {
            throw self::unavailable('An inbound email could not be moved', $exception);
        }
    }

    public function uidValidity(): ?int
    {
        try {
            $validity = $this->folder()->status()['uidvalidity'] ?? null;
        } catch (Throwable $exception) {
            throw self::unavailable('The inbound mailbox status could not be read', $exception);
        }

        return is_numeric($validity) ? (int) $validity : null;
    }

    public function ensureFolders(array $folders): void
    {
        try {
            $this->folder();
            foreach ($folders as $name) {
                if (! $this->client?->getFolderByPath($name, soft_fail: true) instanceof Folder) {
                    $this->client?->createFolder($name, expunge: false);
                }
            }
        } catch (Throwable $exception) {
            throw self::unavailable('The inbound mailbox folders could not be created', $exception);
        }
    }

    public function purge(string $folder, int $olderThanDays): int
    {
        try {
            $this->folder();
            $target = $this->client?->getFolderByPath($folder, soft_fail: true);
            if (! $target instanceof Folder) {
                return 0;
            }

            $messages = $target->query()->whereBefore(now()->subDays($olderThanDays))->get();
            foreach ($messages as $message) {
                $message->delete(expunge: false);
            }
            $this->client?->expunge();

            return $messages->count();
        } catch (Throwable $exception) {
            throw self::unavailable('The inbound mailbox folder could not be emptied', $exception);
        } finally {
            $this->disconnect();
        }
    }

    public function expunge(): void
    {
        try {
            $this->folder();
            $this->client?->openFolder($this->settings->imap('folder') ?: 'INBOX');
            $this->client?->expunge();
        } catch (Throwable $exception) {
            throw self::unavailable('The inbound mailbox could not be expunged', $exception);
        } finally {
            $this->disconnect();
        }
    }

    public function test(): void
    {
        try {
            $this->folder();
        } finally {
            $this->disconnect();
        }
    }

    /**
     * The failure named by its type and the exception class only, never the server's words (#779).
     */
    public static function unavailable(string $what, Throwable $exception): MailboxUnavailable
    {
        return new MailboxUnavailable($what.' ('.class_basename($exception).').', self::failureFor($exception));
    }

    public static function failureFor(Throwable $exception): FailureType
    {
        $text = '';
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $text .= ' '.class_basename($current).' '.strtolower($current->getMessage());
        }

        return match (true) {
            str_contains($text, 'AuthFailedException'), str_contains($text, 'authenticationfailed') => FailureType::LoginFailed,
            str_contains($text, 'timed out'), str_contains($text, 'timeout') => FailureType::Timeout,
            str_contains($text, 'certificate') => FailureType::CertificateUntrusted,
            str_contains($text, 'getaddrinfo'), str_contains($text, 'name or service not known') => FailureType::DnsNotFound,
            str_contains($text, 'FolderFetchingException') => FailureType::FolderNotFound,
            default => FailureType::ConnectionRefused,
        };
    }

    private function supportsMove(): bool
    {
        if ($this->supportsMove === null) {
            try {
                $capabilities = (array) $this->client?->getConnection()->getCapabilities()->validatedData();
            } catch (Throwable) {
                $capabilities = [];
            }
            $this->supportsMove = in_array('MOVE', array_map(static fn (mixed $capability): string => strtoupper((string) $capability), $capabilities), true);
        }

        return $this->supportsMove;
    }

    private function disconnect(): void
    {
        $this->client?->disconnect();
        $this->client = null;
        $this->folder = null;
        $this->supportsMove = null;
    }

    private function folder(): Folder
    {
        if ($this->folder !== null) {
            return $this->folder;
        }

        try {
            $this->client = (new ClientManager)->make([
                'host' => $this->settings->imap('host'),
                'port' => (int) $this->settings->imap('port'),
                'encryption' => $this->settings->imap('encryption') ?: 'ssl',
                'validate_cert' => $this->settings->validateCert(),
                'username' => $this->settings->imap('username'),
                'password' => $this->settings->imap('password'),
                'protocol' => 'imap',
                'timeout' => (int) $this->settings->imap('timeout'),
            ]);
            $this->client->connect();
            $folder = $this->client->getFolderByPath($this->settings->imap('folder') ?: 'INBOX');
        } catch (Throwable $exception) {
            throw self::unavailable('The inbound mailbox could not be reached', $exception);
        }

        if (! $folder instanceof Folder) {
            throw new MailboxUnavailable('The inbound mailbox folder was not found.', FailureType::FolderNotFound);
        }

        return $this->folder = $folder;
    }
}
