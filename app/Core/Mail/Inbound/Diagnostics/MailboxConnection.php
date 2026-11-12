<?php

namespace App\Core\Mail\Inbound\Diagnostics;

use App\Core\Mail\Inbound\InboundMailSettings;
use LogicException;
use SensitiveParameter;

/**
 * The values one connection uses: the saved settings, or the values typed in
 * the form for a single test (#771). The password stays in memory only.
 */
final class MailboxConnection
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $encryption,
        public readonly bool $validateCert,
        public readonly string $username,
        #[SensitiveParameter] private readonly string $password,
        public readonly string $folder = 'INBOX',
        public readonly int $timeout = 15,
    ) {}

    public static function fromSettings(InboundMailSettings $settings): self
    {
        return new self(
            host: $settings->imap('host'),
            port: (int) $settings->imap('port'),
            encryption: $settings->imap('encryption') ?: 'ssl',
            validateCert: $settings->validateCert(),
            username: $settings->imap('username'),
            password: $settings->imap('password'),
            folder: $settings->imap('folder') ?: 'INBOX',
            timeout: max(1, (int) $settings->imap('timeout')),
        );
    }

    public function password(): string
    {
        return $this->password;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'validateCert' => $this->validateCert,
            'folder' => $this->folder,
            'timeout' => $this->timeout,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A mailbox connection holds a password and is never serialised.');
    }
}
