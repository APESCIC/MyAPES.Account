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
 * environment only and never appear in errors or logs.
 */
final class ImapMailbox implements InboundMailbox
{
    private ?Client $client = null;

    private ?Folder $folder = null;

    public function __construct(private readonly InboundMailSettings $settings) {}

    public function messages(int $limit): iterable
    {
        try {
            $messages = $this->folder()->query()->whereAll()->setFetchOrder('asc')->limit($limit)->get();
        } catch (Throwable $exception) {
            throw new MailboxUnavailable('The inbound mailbox could not be read ('.class_basename($exception).').');
        }

        foreach ($messages as $message) {
            yield new class($message) implements MailboxMessage
            {
                public function __construct(private readonly Message $message) {}

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
                'validate_cert' => (bool) config('inbound-mail.imap.validate_cert'),
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
