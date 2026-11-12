<?php

namespace App\Core\Mail\Inbound;

use App\Core\Mail\Inbound\Contracts\InboundMailbox;
use App\Core\Mail\Inbound\Contracts\MailboxMessage;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/**
 * The communications mailbox over IMAPS (#579). Credentials come from the
 * environment or Admin → Settings (#737) and never appear in errors or logs.
 */
final class ImapMailbox implements InboundMailbox
{
    private ?Client $client = null;

    private ?Folder $folder = null;

    public function __construct(private readonly InboundMailSettings $settings) {}

    public function messages(int $limit, ?int $afterUid = null): iterable
    {
        try {
            $query = $this->folder()->query()->setFetchOrder('asc')->limit($limit);
            $messages = $afterUid !== null ? $query->getByUidGreater($afterUid) : $query->whereAll()->get();
        } catch (Throwable $exception) {
            throw new MailboxUnavailable('The inbound mailbox could not be read ('.class_basename($exception).').');
        }

        foreach ($messages as $message) {
            yield new class($message) implements MailboxMessage
            {
                public function __construct(private readonly Message $message) {}

                public function uid(): ?int
                {
                    $uid = $this->message->getUid();

                    return is_numeric($uid) ? (int) $uid : null;
                }

                public function raw(): string
                {
                    return rtrim((string) $this->message->getHeader()?->raw)."\r\n\r\n".$this->message->getRawBody();
                }

                public function delete(): void
                {
                    $this->message->delete(expunge: false);
                }
            };
        }
    }

    public function uidValidity(): ?int
    {
        try {
            $validity = $this->folder()->status()['uidvalidity'] ?? null;
        } catch (Throwable $exception) {
            throw new MailboxUnavailable('The inbound mailbox status could not be read ('.class_basename($exception).').');
        }

        return is_numeric($validity) ? (int) $validity : null;
    }

    public function expunge(): void
    {
        try {
            $this->folder();
            $this->client?->expunge();
        } catch (Throwable $exception) {
            throw new MailboxUnavailable('The inbound mailbox could not be expunged ('.class_basename($exception).').');
        } finally {
            $this->client?->disconnect();
            $this->client = null;
            $this->folder = null;
        }
    }

    public function test(): void
    {
        try {
            $this->folder();
        } finally {
            $this->client?->disconnect();
            $this->client = null;
            $this->folder = null;
        }
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
            throw new MailboxUnavailable('The inbound mailbox could not be reached ('.class_basename($exception).').');
        }

        if (! $folder instanceof Folder) {
            throw new MailboxUnavailable('The inbound mailbox folder was not found.');
        }

        return $this->folder = $folder;
    }
}
